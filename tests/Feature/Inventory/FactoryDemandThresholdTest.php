<?php
namespace Tests\Feature\Inventory;

use App\Modules\Identity\Infrastructure\Models\{Permission,Role,User};
use App\Modules\Inventory\Application\FactoryDemandForecast;
use App\Modules\Inventory\Infrastructure\Models\{Warehouse,WarehouseInventory};
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FactoryDemandThresholdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void { parent::setUp(); $this->withoutVite(); }

    public function test_threshold_calculation_and_zero_sales_with_manual_override(): void
    {
        $r=FactoryDemandForecast::calculate(339,12,null);
        $this->assertSame(113.0,$r['monthly_average']);
        $this->assertSame(226,$r['two_month_demand']);
        $this->assertSame(214,$r['shortage_quantity']);
        $this->assertSame('warning',$r['status']);
        $this->assertSame('no_sales',FactoryDemandForecast::calculate(0,1,null)['status']);
        $this->assertSame('warning',FactoryDemandForecast::calculate(0,1,20)['status']);
        $this->assertSame('normal',FactoryDemandForecast::calculate(339,12,10)['status']);
    }

    public function test_paid_original_customer_orders_drive_demand_and_override_can_reset(): void
    {
        $w=Warehouse::where('code','FACTORY')->firstOrFail();
        $p=Product::create(['sku'=>'FACT-PAID','name'=>'Test','source_type'=>'manual','product_type'=>'simple']);
        $stock=WarehouseInventory::create(['warehouse_id'=>$w->id,'product_id'=>$p->id,'quantity_on_hand'=>12,'quantity_reserved'=>2]);
        $this->order($p,300,true,'customer','pending_shipment','completed');
        $this->order($p,200,false,'customer','pending_shipment','pending');
        $this->order($p,60,true,'customer','cancelled','refunded');
        $this->order($p,99,true,'kol_fulfillment','shipped','completed');
        $user=$this->userWith(['inventory.view','inventory.operate']);
        $this->actingAs($user)->get('/inventory/factory')->assertOk()->assertInertia(fn($page)=>$page
            ->where('warehouse.inventories.0.factory_demand.sales_90d',300)
            ->where('warehouse.inventories.0.factory_demand.two_month_demand',200)
            ->where('warehouse.inventories.0.factory_demand.shortage_quantity',190)
            ->where('warehouse.inventories.0.factory_demand.status','warning'));
        $this->actingAs($user)->patch('/inventory/factory/stock/'.$stock->id.'/factory-demand',['manual_two_month_demand'=>9])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(9,$stock->fresh()->two_month_demand_override);
        $this->actingAs($user)->get('/inventory/factory')->assertOk()->assertInertia(fn($page)=>$page
            ->where('warehouse.inventories.0.factory_demand.two_month_demand',9)
            ->where('warehouse.inventories.0.factory_demand.status','normal')
            ->where('warehouse.inventories.0.factory_demand.is_manual',true));
        // Weekly stock snapshot must update quantity without erasing the manual demand override.
        $service=app(\App\Modules\Inventory\Application\InventoryBatchSyncService::class);
        $batch=$service->preview($w,[new \App\Modules\Inventory\Application\Sync\NormalizedRow(1,'FACT-PAID','sellable',null,null,3)],'paste','partial',null,null,$user);
        $service->confirm($batch,$user);
        $this->assertSame(3,$stock->fresh()->quantity_on_hand);
        $this->assertSame(9,$stock->fresh()->two_month_demand_override);
        $this->actingAs($user)->patch('/inventory/factory/stock/'.$stock->id.'/factory-demand',['manual_two_month_demand'=>null])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($stock->fresh()->two_month_demand_override);
        $this->assertDatabaseHas('audit_records',['action'=>'inventory.factory_demand_override_updated']);
    }

    public function test_security_invalid_values_and_other_warehouse_unchanged(): void
    {
        $w=Warehouse::where('code','FACTORY')->firstOrFail();
        $de=Warehouse::where('slug','de')->firstOrFail();
        $p=Product::create(['sku'=>'FACT-SEC','name'=>'Test','source_type'=>'manual','product_type'=>'simple']);
        $f=WarehouseInventory::create(['warehouse_id'=>$w->id,'product_id'=>$p->id,'quantity_on_hand'=>12,'quantity_reserved'=>0]);
        $o=WarehouseInventory::create(['warehouse_id'=>$de->id,'product_id'=>$p->id,'quantity_on_hand'=>2,'quantity_reserved'=>0]);
        $this->actingAs($this->userWith(['inventory.view']))->patch('/inventory/factory/stock/'.$f->id.'/factory-demand',['manual_two_month_demand'=>80])->assertForbidden();
        $this->actingAs($this->userWith(['inventory.view','inventory.operate']))->patch('/inventory/de/stock/'.$o->id.'/factory-demand',['manual_two_month_demand'=>80])->assertNotFound();
        $this->patch('/inventory/factory/stock/'.$o->id.'/factory-demand',['manual_two_month_demand'=>80])->assertNotFound();
        $this->patch('/inventory/factory/stock/'.$f->id.'/factory-demand',['manual_two_month_demand'=>-1])->assertSessionHasErrors('manual_two_month_demand');
        $this->assertNull($f->fresh()->two_month_demand_override);
        $this->actingAs($this->userWith(['inventory.view']))->get('/inventory/de')->assertOk()->assertInertia(fn($page)=>$page
            ->where('warehouse.inventories.0.factory_demand',null)
            ->where('warehouse.inventories.0.stock_status','low_stock'));
    }

    private function order(Product $product,int $qty,bool $paid,string $kind,string $status,string $provider): void
    {
        static $seq=0;
        $o=Order::create(['internal_order_number'=>'FACT-TEST-'.(++$seq),'source_type'=>$kind==='customer'?'woocommerce':'manual_other','order_kind'=>$kind,'order_status'=>$status,'order_date'=>today()]);
        $o->items()->create(['product_id'=>$product->id,'sku'=>$product->sku,'product_summary'=>$product->name,'quantity'=>$qty,'unit_price'=>1,'line_revenue'=>$qty]);
        $o->financials()->create(['paid_at'=>$paid?now():null,'provider_order_status'=>$provider]);
    }
    private function userWith(array $perms): User
    {
        $user=User::factory()->create(['is_active'=>true]);$role=Role::create(['key'=>'factory-test-'.uniqid(),'label'=>'Factory Test']);
        foreach($perms as $key){$p=Permission::firstOrCreate(['key'=>$key],['label'=>$key]);$role->permissions()->syncWithoutDetaching([$p->id]);}
        $user->roles()->attach($role);return $user;
    }
}
