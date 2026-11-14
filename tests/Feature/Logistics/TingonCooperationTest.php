<?php

namespace Tests\Feature\Logistics;

use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class TingonCooperationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
    }

    public function test_tingon_menu_entries_match_runtime_and_approved_baseline(): void
    {
        $approved=collect(json_decode(file_get_contents(base_path('menu/APPROVED_MENU.json')),true,flags:JSON_THROW_ON_ERROR)['items'])->keyBy('code');
        $runtime=collect(config('erp_shell_navigation'));
        foreach ([
            ['orders','orders-collaboration','合作订单','/orders/collaboration'],
            ['logistics','logistics-tingon','Tingon发货','/logistics/tingon'],
        ] as [$parent,$code,$label,$path]) {
            $runtimeEntry=collect($runtime->firstWhere('key',$parent)['children'])->firstWhere('key',$code);
            self::assertSame($label,$runtimeEntry['label']);
            self::assertSame($path,$runtimeEntry['path']);
            self::assertSame($label,$approved[$code]['label_zh']);
            self::assertSame($path,$approved[$code]['url']);
            self::assertSame($parent,$approved[$code]['parent_code']);
        }
        self::assertTrue(app('router')->has('orders.collaboration'));
        self::assertTrue(app('router')->has('logistics.tingon'));
    }

    public function test_tingon_is_available_for_manual_allocation_and_warehouse_identity_is_not_external_procurement(): void
    {
        $user=$this->actor(['logistics.view','logistics.operate','orders.view']);
        $order=$this->order('beyourlover', false);
        $warehouse=Warehouse::query()->where('code','TINGON')->firstOrFail();
        self::assertSame('partner',$warehouse->ownership_type);
        self::assertSame('合作-Tingon',$warehouse->name);

        $this->actingAs($user)->get('/logistics/wait-stock')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Logistics/FulfillmentAllocation')
                ->where('warehouses',fn ($rows) => collect($rows)->contains(fn ($row) => $row['code']==='TINGON'))
        );
        $this->actingAs($user)->postJson('/logistics/orders/'.$order->id.'/fulfillment-allocation/manual',[
            'assignments'=>[['order_item_id'=>$order->items->first()->id,'source_code'=>'TINGON']],
        ])->assertOk();

        self::assertSame('TINGON',$order->fresh()->shipment?->warehouse?->code);
        self::assertNull($order->fresh()->shipment?->carrier);
        self::assertSame('pending',$order->fresh()->shipment?->shipment_status);
        $this->assertDatabaseCount('orders',1);

        $this->actingAs($user)->get('/orders/collaboration')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Orders/Index')
                ->where('orders.total',1)->where('orders.data.0.id',$order->id)
        );
        $this->actingAs($user)->get('/logistics/tingon?view=pending')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Logistics/TingonFulfillment')
                ->where('orders.total',1)->where('orders.data.0.order_id',$order->id)
        );
        $this->actingAs($user)->get('/orders/procurement')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('orders.total',0)
        );
    }

    public function test_brand_and_fulfillment_are_separate_dimensions_and_partner_rows_are_mutable_only_in_partner_tab(): void
    {
        $viewer=$this->actor(['logistics.view','orders.view']);
        $brand=$this->order('Tingon',false);
        $brand->shipment()->create([
            'warehouse_id'=>Warehouse::query()->where('code','FACTORY')->firstOrFail()->id,
            'shipment_status'=>'pending',
        ]);
        $partner=$this->order('BYL',true);
        $this->actingAs($viewer)->get('/logistics/tingon?view=brand')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('orders.total',1)
                ->where('orders.data.0.order_id',$brand->id)
                ->where('orders.data.0.can_edit',false)
        );
        $this->actingAs($viewer)->get('/logistics/tingon?view=all')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('orders.total',1)
                ->where('orders.data.0.order_id',$partner->id)
                ->where('orders.data.0.can_edit',true)
        );
        $this->actingAs($viewer)->get('/orders/collaboration')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('orders.total',1)
        );
    }

    public function test_split_original_parent_appears_once_in_cooperation_and_tingon_child_enters_dispatch_queue(): void
    {
        $operator=$this->actor(['logistics.view','logistics.operate','orders.view']);
        $order=$this->order('BYL',false);
        $initialItemId=$order->items->first()->id;
        $second=Product::query()->create([
            'sku'=>'SPLIT-BYL-'.uniqid(),'name'=>'Split Product',
            'source_type'=>'manual','product_type'=>'simple','is_active'=>true,'brand'=>'BYL',
        ]);
        $other=$order->items()->create([
            'product_id'=>$second->id,'product_summary'=>$second->name,'sku'=>$second->sku,
            'quantity'=>1,'unit_price'=>'10.00','line_revenue'=>'10.00',
        ]);
        $result = $this->actingAs($operator)->postJson('/logistics/orders/'.$order->id.'/fulfillment-allocation/manual',[
            'assignments'=>[
                ['order_item_id'=>$initialItemId,'source_code'=>'FACTORY'],
                ['order_item_id'=>$other->id,'source_code'=>'TINGON'],
            ],
            'stock_exception_acknowledged'=>true,
        ]);
        $result->assertOk();
        self::assertSame(2,$order->fresh()->fulfillmentChildren()->count());
        $this->actingAs($operator)->get('/orders/collaboration')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('orders.total',1)
                ->where('orders.data.0.id',$order->id)
        );
        $this->actingAs($operator)->get('/logistics/tingon?view=pending')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('orders.total',1)
                ->where('orders.data.0.warehouse','合作-Tingon')
        );
    }

    public function test_tingon_mutations_reject_unassigned_orders_and_view_only_users(): void
    {
        $viewer=$this->actor(['logistics.view','orders.view']);
        $operator=$this->actor(['logistics.view','logistics.operate','orders.view']);
        $other=$this->order('Tingon',false);
        $partner=$this->order('BYL',true);
        $this->actingAs($viewer)->postJson('/logistics/tingon/orders/'.$partner->id.'/ship',[
            'carrier'=>'USPS','tracking_number'=>'TG-TEST-001',
        ])->assertForbidden();
        $this->actingAs($operator)->postJson('/logistics/tingon/orders/'.$other->id.'/save',[
            'carrier'=>'USPS','tracking_number'=>'TG-TEST-001',
        ])->assertStatus(409);
        $this->assertDatabaseMissing('shipments',['order_id'=>$other->id,'tracking_number'=>'TG-TEST-001']);
        $this->actingAs($operator)->postJson('/logistics/tingon/orders/'.$partner->id.'/ship',[
            'carrier'=>'USPS',
        ])->assertUnprocessable();
        self::assertSame('pending',$partner->fresh()->shipment->shipment_status);
    }

    public function test_tingon_manual_shipping_requires_real_tracking_then_rejects_duplicate_and_allows_tracking_correction(): void
    {
        $operator=$this->actor(['logistics.view','logistics.operate','orders.view']);
        $order=$this->order('BYL',true);
        $this->actingAs($operator)->post('/logistics/tingon/orders/'.$order->id.'/save', [
            'carrier'=>'USPS','tracking_number'=>'TG-FAKE-LOCAL-001',
        ])->assertRedirect();
        self::assertSame('pending',$order->fresh()->shipment->shipment_status);
        self::assertSame('TG-FAKE-LOCAL-001',$order->fresh()->shipment->tracking_number);
        $this->actingAs($operator)->post('/logistics/tingon/orders/'.$order->id.'/ship',[
            'carrier'=>'USPS','tracking_number'=>'TG-FAKE-LOCAL-001',
        ])->assertRedirect();
        self::assertSame('shipped',$order->fresh()->shipment->shipment_status);
        self::assertSame('shipped',$order->fresh()->order_status->value);
        $this->actingAs($operator)->postJson('/logistics/tingon/orders/'.$order->id.'/ship',[
            'carrier'=>'USPS','tracking_number'=>'TG-FAKE-LOCAL-001',
        ])->assertStatus(409);
        $this->actingAs($operator)->post('/logistics/tingon/orders/'.$order->id.'/tracking',[
            'tracking_number'=>'TG-FAKE-LOCAL-002',
        ])->assertRedirect();
        self::assertSame('TG-FAKE-LOCAL-002',$order->fresh()->shipment->tracking_number);
        $this->actingAs($operator)->get('/logistics/tingon?view=sent')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('orders.total',1)
        );
    }

    private function actor(array $permissions): User
    {
        $role=Role::query()->create(['key'=>'tingon-'.uniqid(),'label'=>'Tingon UAT']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key'=>$permission],['label'=>$permission]);
        }
        $user=User::factory()->create(['is_active'=>true]);
        $user->roles()->attach($role);
        return $user;
    }

    private function order(string $brand, bool $partner): Order
    {
        $product=Product::query()->create([
            'sku'=>'TINGON-PRODUCT-'.uniqid(),'name'=>'Tingon feature test',
            'source_type'=>'manual','product_type'=>'simple','is_active'=>true,'brand'=>$brand,
        ]);
        $order=Order::query()->create([
            'internal_order_number'=>'TINGON-'.uniqid(),'erp_order_id'=>'007-TEST-'.random_int(100000,999999),
            'source_type'=>'manual','order_kind'=>'customer','external_order_number'=>'EXT-'.uniqid(),
            'order_date'=>'2026-11-14','order_status'=>'pending_shipment','currency'=>'USD',
        ]);
        $order->address()->create([
            'recipient_name'=>'Tingon Test Customer','phone'=>'1234567890','country'=>'US',
            'province_state'=>'CA','city'=>'Los Angeles','address_line_1'=>'123 Test Ave','postal_code'=>'90001',
        ]);
        $order->items()->create([
            'product_id'=>$product->id,'product_summary'=>$product->name,'sku'=>$product->sku,
            'quantity'=>1,'unit_price'=>'10.00','line_revenue'=>'10.00',
        ]);
        if($partner){
             $order->shipment()->create([
                'warehouse_id'=>Warehouse::query()->where('code','TINGON')->firstOrFail()->id,
                'shipment_status'=>'pending',
            ]);
        }
        return $order->fresh(['items','shipment.warehouse']);
    }
}
