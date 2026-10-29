<?php
namespace Tests\Feature\Products;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
final class Barcode69EndpointTest extends TestCase {
    use RefreshDatabase;
    public function test_europe_domestic_manifest(): void {
        $user=User::factory()->create(['is_active'=>true]);
        $role=Role::create(['key'=>'barcode-view','label'=>'QA']);
        $role->permissions()->firstOrCreate(['key'=>'logistics.view'],['label'=>'Logistics view']);
        $user->roles()->attach($role);
        $p=Product::create(['source_type'=>'manual','product_type'=>'simple','sku'=>'VX357A','name'=>'QA','barcode_69'=>'6977291390537']);
        $o=Order::create(['internal_order_number'=>'B69-QA','erp_order_id'=>'007-B69-QA','source_type'=>'manual','order_kind'=>'customer','order_date'=>now()->toDateString(),'order_status'=>'pending_shipment','currency'=>'USD']);
        $o->address()->create(['country'=>'FR','recipient_name'=>'QA','city'=>'Paris','postal_code'=>'75001','address_line_1'=>'Test','phone'=>'000']);
        $w=Warehouse::create(['slug'=>'qa-cn','code'=>'QACN','name'=>'QA China','country_code'=>'CN','type'=>'owned','is_domestic'=>true,'is_active'=>true]);
        $o->shipment()->create(['warehouse_id'=>$w->id,'shipment_status'=>'pending']);
        $o->items()->create(['product_id'=>$p->id,'product_summary'=>'QA','sku'=>'VX357A','quantity'=>1,'unit_price'=>0,'line_revenue'=>0]);
        $this->actingAs($user)->getJson('/logistics/china/orders/'.$o->id.'/barcode-69')->assertOk()->assertJsonPath('items.0.barcode_69','6977291390537');
        $o->address()->update(['country'=>'US']);
        $this->actingAs($user)->getJson('/logistics/china/orders/'.$o->id.'/barcode-69')->assertStatus(422);
        $o->address()->update(['country'=>'FR']);
        $w->update(['is_domestic'=>false]);
        $this->actingAs($user)->getJson('/logistics/china/orders/'.$o->id.'/barcode-69')->assertStatus(422);
    }
}
