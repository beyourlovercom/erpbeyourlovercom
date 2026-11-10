<?php

namespace Tests\Feature\Logistics;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;
use Mockery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DomesticInlineTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
    }

    public function test_approved_domestic_row_saves_tracking_as_draft_then_explicitly_confirms_shipping(): void
    {
        $actor = $this->userWith(['logistics.view', 'logistics.operate']);
        $order = $this->factoryOrder(true);
        $draftUrl = '/logistics/china/orders/'.$order->id.'/tracking-draft';
        $shipUrl = '/logistics/china/orders/'.$order->id.'/quick-ship';

        $this->actingAs($actor)->get('/logistics/china?view=pending')->assertOk()->assertInertia(
            fn($page) => $page->where('canOperate', true)->where('rows.total', 1)
                ->where('rows.data.0.review_status', 'approved')
        );

        $this->actingAs($actor)->post($draftUrl, ['tracking_number' => '4PX0123456789'])
            ->assertRedirect();
        self::assertSame('pending_shipment', $order->fresh()->order_status->value);
        self::assertSame('pending', $order->fresh()->shipment->shipment_status);
        self::assertSame('4PX0123456789', $order->fresh()->shipment->tracking_number);
        self::assertNull($order->fresh()->shipment->shipped_at);
        self::assertNull($order->manualSync()->first());

        $this->actingAs($actor)->post($shipUrl, ['tracking_number' => '4PX0123456789'])
            ->assertRedirect();
        self::assertSame('shipped', $order->fresh()->order_status->value);
        self::assertSame('shipped', $order->fresh()->shipment->shipment_status);
        self::assertSame('4PX0123456789', $order->fresh()->shipment->tracking_number);
        self::assertNotNull($order->fresh()->shipment->shipped_at);
        $this->actingAs($actor)->get('/logistics/china?view=pending')->assertInertia(
            fn($page) => $page->where('rows.total', 0)
        );
        $this->actingAs($actor)->get('/logistics/china?view=today')->assertInertia(
            fn($page) => $page->where('rows.total', 1)
        );
    }

    public function test_woocommerce_factory_quick_ship_stages_outbox_without_immediate_network(): void
    {
        $gateway = Mockery::mock(WooCommerceTrackingGateway::class);
        $gateway->shouldReceive('descriptor')->andReturn([
            'configured' => true, 'connection_key' => 'shop-domestic',
        ]);
        $gateway->shouldNotReceive('syncTracking');
        $gateway->shouldNotReceive('syncOrderStatus');
        $this->app->instance(WooCommerceTrackingGateway::class, $gateway);

        $actor = $this->userWith(['logistics.view', 'logistics.operate']);
        $order = $this->factoryOrder(true);
        $order->update(['source_type' => 'woocommerce']);
        $order->items()->update(['woocommerce_line_item_id' => 54321]);
        $order->externalReferences()->create([
            'provider' => 'woocommerce',
            'connection_key' => 'shop-domestic',
            'reference' => '987654',
            'provider_modified_at' => now(),
        ]);
        $order->financials()->create([
            'currency' => 'USD',
            'provider' => 'woocommerce',
            'provider_reference' => '987654',
            'provider_order_status' => 'processing',
            'provider_modified_at' => now(),
            'paid_at' => now(),
            'provider_order_total' => '10.00',
            'revenue_provenance' => 'provider_import',
            'completeness_status' => 'INCOMPLETE',
        ]);

        $this->actingAs($actor)
            ->post('/logistics/china/orders/'.$order->id.'/quick-ship', [
                'tracking_number' => '4PX-PERIODIC-1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', '已确认 ERP 发货，官网物流由同步模块每 30 分钟处理，请查看同步状态。');

        self::assertSame('shipped', $order->fresh()->shipment->shipment_status);
        $this->assertDatabaseHas('sync_outbox', [
            'operation' => 'woocommerce.tracking_outbound',
            'aggregate_id' => (string) $order->fresh()->shipment->id,
            'status' => 'pending',
            'attempts' => 0,
        ]);
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $order->id,
            'website_sync_status' => 'pending',
        ]);
    }

    public function test_factory_carrier_and_service_require_explicit_authorized_selection_before_shipping(): void
    {
        $operator = $this->userWith(['logistics.view', 'logistics.operate']);
        $viewer = $this->userWith(['logistics.view']);
        $order = $this->factoryOrder(true);
        $order->shipment()->update([
            'carrier_selection_source' => 'automatic',
            'carrier' => '4PX',
            'service' => 'SD经济挂号-带电',
        ]);
        $route = '/logistics/china/orders/'.$order->id.'/carrier-service';
        $ship = '/logistics/china/orders/'.$order->id.'/quick-ship';

        $this->actingAs($operator)->get('/logistics/china?view=pending')
            ->assertInertia(fn($page) => $page
                ->where('rows.data.0.carrier_selection_source', 'automatic')
                ->where('rows.data.0.export_blockers.0', '请人工确认物流商和服务类型')
                ->has('carrierOptions'));
        $this->actingAs($operator)->post($ship, ['tracking_number'=>'4PX-UNCONFIRMED'])
            ->assertStatus(422);
        self::assertSame('pending', $order->fresh()->shipment->shipment_status);
        $this->actingAs($viewer)->post($route, [
            'carrier'=>'4PX', 'service'=>'SD经济挂号-带电',
        ])->assertForbidden();
        self::assertSame('automatic', $order->fresh()->shipment->carrier_selection_source);
        $this->actingAs($operator)->post($route, [
            'carrier'=>'4PX', 'service'=>'NOT-A-REAL-SERVICE',
        ])->assertStatus(422);
        self::assertSame('automatic', $order->fresh()->shipment->carrier_selection_source);

        $this->actingAs($operator)->post($route, [
            'carrier'=>'4PX', 'service'=>'SD经济挂号-带电',
        ])->assertRedirect();
        $this->assertDatabaseHas('shipments', [
            'order_id'=>$order->id, 'carrier'=>'4PX', 'service'=>'SD经济挂号-带电',
            'carrier_selection_source'=>'manual', 'shipment_status'=>'pending',
        ]);
        $this->actingAs($operator)->get('/logistics/china?view=pending')->assertInertia(
            fn($page) => $page->where('rows.data.0.carrier', '4PX')
                ->where('rows.data.0.service', 'SD经济挂号-带电')
                ->where('rows.data.0.carrier_selection_source', 'manual'),
        );
        $this->actingAs($operator)->post($ship, ['tracking_number'=>'4PX-CONFIRMED'])
            ->assertRedirect();
        self::assertSame('shipped', $order->fresh()->shipment->shipment_status);
    }

    public function test_factory_warehouse_only_draft_never_infers_a_provider_or_service(): void
    {
        $actor=$this->userWith(['logistics.view','logistics.operate']);
        $order=$this->factoryOrder(true);
        $order->shipment()->update([
            'carrier'=>null,'service'=>null,'carrier_service_id'=>null,'carrier_selection_source'=>null,
        ]);
        app(\App\Modules\Logistics\Application\ShipmentService::class)
            ->saveDraft($order->fresh(), ['warehouse_id'=>$order->shipment->warehouse_id], $actor);
        $shipment=$order->fresh()->shipment;
        self::assertNull($shipment->carrier);
        self::assertNull($shipment->service);
        self::assertNull($shipment->carrier_selection_source);
    }

    public function test_unreviewed_or_stockout_cannot_save_or_ship_even_by_manual_route(): void
    {
        $actor = $this->userWith(['logistics.view', 'logistics.operate']);
        foreach (['unreviewed','stockout'] as $state) {
            $order = $this->factoryOrder($state==='stockout');
            if ($state==='stockout') {
                $order->forceFill(['workflow_status' => 'out_of_stock'])->save();
                $order->operationalMarkers()->create(['marker' => 'domestic_stockout', 'marked_at' => now()]);
            }
            $this->actingAs($actor)
                ->post('/logistics/china/orders/'.$order->id.'/tracking-draft',['tracking_number'=>'TRACKME'])
                ->assertSessionHasErrors('tracking_number');
            $this->actingAs($actor)
                ->post('/logistics/china/orders/'.$order->id.'/quick-ship',['tracking_number'=>'TRACKME'])
                ->assertSessionHasErrors('tracking_number');
            self::assertNull($order->fresh()->shipment->tracking_number);
            self::assertSame('pending', $order->fresh()->shipment->shipment_status);
        }
    }

    public function test_readonly_permissions_and_validation_block_tracking_mutation(): void
    {
        $order = $this->factoryOrder(true);
        $url = '/logistics/china/orders/'.$order->id.'/tracking-draft';
        $ship = '/logistics/china/orders/'.$order->id.'/quick-ship';
        $this->post($url,['tracking_number'=>'ABC123'])->assertRedirect('/login');
        $viewer=$this->userWith(['logistics.view']);
        $this->actingAs($viewer)->post($url,['tracking_number'=>'ABC123'])->assertForbidden();
        $this->actingAs($viewer)->post($ship,['tracking_number'=>'ABC123'])->assertForbidden();
        $actor=$this->userWith(['logistics.view','logistics.operate']);
        $this->actingAs($actor)->post($ship,['tracking_number'=>''])->assertSessionHasErrors('tracking_number');
        $this->actingAs($actor)->post($url,['tracking_number'=>'中文备注 含空格'])->assertSessionHasErrors('tracking_number');
        self::assertNull($order->fresh()->shipment->tracking_number);
        self::assertSame('pending', $order->fresh()->shipment->shipment_status);
    }

    public function test_approved_split_child_can_be_confirmed_without_shipping_the_original_parent(): void
    {
        $actor = $this->userWith(['logistics.view','logistics.operate']);
        $parent = $this->factoryOrder(false);
        $parent->shipment()->delete();
        $child = $this->factoryOrder(true);
        $child->forceFill([
            'order_kind' => 'fulfillment_derived',
            'fulfillment_parent_id' => $parent->id,
            'fulfillment_role' => 'original',
            'fulfillment_suffix' => 'A',
        ])->save();

        $this->actingAs($actor)->post('/logistics/china/orders/'.$child->id.'/quick-ship', [
            'tracking_number' => '4PX-SPLIT-CHILD-1',
        ])->assertRedirect();

        self::assertSame('shipped', $child->fresh()->shipment->shipment_status);
        self::assertSame('4PX-SPLIT-CHILD-1', $child->fresh()->shipment->tracking_number);
        self::assertNotEquals('shipped', $parent->fresh()->order_status->value);
    }

    public function test_cross_warehouse_and_duplicate_tracking_are_rejected(): void
    {
        $actor=$this->userWith(['logistics.view','logistics.operate']);
        $first=$this->factoryOrder(true);
        $second=$this->factoryOrder(true);
        $this->actingAs($actor)->post('/logistics/china/orders/'.$first->id.'/tracking-draft', [
            'tracking_number'=>'4PX-DUPLICATE',
        ])->assertRedirect();
        $this->actingAs($actor)->post('/logistics/china/orders/'.$second->id.'/quick-ship', [
            'tracking_number'=>'4PX-DUPLICATE',
        ])->assertSessionHasErrors('tracking_number');
        self::assertSame('pending', $second->fresh()->shipment->shipment_status);

        $second->shipment()->update(['warehouse_id' => Warehouse::where('code','US-LA')->firstOrFail()->id]);
        $this->actingAs($actor)->post('/logistics/china/orders/'.$second->id.'/tracking-draft', [
            'tracking_number'=>'NEW0123',
        ])->assertStatus(409);
        self::assertNull($second->fresh()->shipment->tracking_number);
    }

    private function userWith(array $permissions): User
    {
        $user=User::factory()->create(['is_active'=>true]);
        $role=Role::create(['key'=>'domestic-inline-'.uniqid(), 'label'=>'Domestic Inline Test']);
        foreach($permissions as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key'=>$key],['label'=>$key]));
        }
        $user->roles()->attach($role);
        return $user;
    }

    private function factoryOrder(bool $reviewed): Order
    {
        $order=Order::query()->create([
            'erp_order_id'=>'007-UAT-'.uniqid(), 'internal_order_number'=>'INT-'.uniqid(),
            'source_type'=>'manual','order_kind'=>'customer','external_order_number'=>'EXT-'.uniqid(),
            'order_date'=>'2026-11-03','order_status'=>'pending_shipment','currency'=>'USD',
        ]);
        $order->address()->create([
            'recipient_name'=>'Test Recipient','country'=>'US','province_state'=>'CA',
            'city'=>'Los Angeles','address_line_1'=>'10 Test St','postal_code'=>'90001','phone'=>'1234567890',
        ]);
        $order->items()->create([
            'product_summary'=>'Test Product','sku'=>'MF414A','quantity'=>1,'unit_price'=>'10.00',
            'line_revenue'=>'10.00',
        ]);
        $order->shipment()->create([
            'warehouse_id'=>Warehouse::where('code','FACTORY')->firstOrFail()->id,
            'shipment_status'=>'pending','carrier'=>'4PX','service'=>'SD经济挂号-带电',
            'warehouse_selection_source'=>'manual','carrier_selection_source'=>'manual',
        ]);
        if($reviewed)$order->operationalMarkers()->create(['marker'=>'domestic_reviewed','marked_at'=>now()]);
        return $order->fresh(['shipment','operationalMarkers']);
    }
}
