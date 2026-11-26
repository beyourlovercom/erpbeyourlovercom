<?php
namespace Tests\Feature\Orders;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Orders\Application\OrderLogisticsAuthority;
use App\Modules\Orders\Application\OrderWorkflowStatusPolicy;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class GlobalLogisticsAuthorityV1Test extends TestCase
{
 use RefreshDatabase;
 protected function setUp(): void {
  parent::setUp(); $this->withoutVite();config()->set('inertia.testing.ensure_pages_exist',false);
  Http::preventStrayRequests();Queue::fake();Mail::fake();
 }
 private function actor(array $permissions): User {
  $u=User::factory()->create(['is_active'=>true]);
  $r=Role::create(['key'=>'global-logistics-'.uniqid(),'label'=>'Global Logistics Test']);
  foreach($permissions as $key)$r->permissions()->attach(Permission::firstOrCreate(['key'=>$key],['label'=>$key]));
  $u->roles()->attach($r);return $u;
 }
 public function test_logistics_actor_can_reclassify_and_reroute_across_warehouses_but_not_edit_order_or_forge_evidence(): void {
  $a=$this->actor(['orders.view','logistics.view','logistics.operate','domestic.review']);
  $b=$this->actor(['orders.view','logistics.view','logistics.operate','domestic.review']);
  $reader=$this->actor(['orders.view','logistics.view']);
  $this->assertFalse($a->can('orders.edit'));$this->assertFalse($b->can('orders.edit'));
  $warehouses=[];
  foreach(['FACTORY','US-NY','US-LA','US-SM','DE','CA'] as $code)$warehouses[$code]=Warehouse::query()->updateOrCreate(['code'=>$code],['slug'=>strtolower($code),'name'=>$code,'type'=>'warehouse','is_active'=>true,'priority'=>1]);
  $o=Order::query()->create(['erp_order_id'=>'UAT-L-'.uniqid(),'internal_order_number'=>'INT-'.uniqid(),'external_order_number'=>'EXT-'.uniqid(),'source_type'=>'manual','order_kind'=>'customer','order_status'=>'pending_shipment','order_date'=>'2026-11-26','currency'=>'USD']);
  $path='/orders/'.$o->id;
  foreach([[$a,'FACTORY'],[$b,'US-NY'],[$a,'US-LA'],[$b,'DE'],[$a,'CA'],[$b,'US-SM'],[$a,'FACTORY']] as $i=>[$actor,$code]){
   $this->actingAs($actor)->patchJson($path.'/warehouse-route',['warehouse_code'=>$code,'expected_routing_revision'=>$i])->assertOk()->assertJsonPath('routing_warehouse.code',$code);
   $this->assertEquals($warehouses[$code]->id,$o->fresh()->shipment->warehouse_id);
   $policy=app(OrderWorkflowStatusPolicy::class)->for($o->fresh(),$actor);
   $this->assertTrue($policy['can_edit']);
   foreach(['pending_allocation','pending_review','sending','out_of_stock','exception','missing_info','finished','completed','cancelled'] as $status)$this->assertTrue(collect($policy['options'])->firstWhere('code',$status)['mutable'],$status);
   $this->assertFalse(collect($policy['options'])->firstWhere('code','paid')['mutable']);
  }
  $this->actingAs($b)->patchJson($path.'/workflow-status',['workflow_status'=>'pending_review','expected_workflow_revision'=>0])->assertOk();
  $this->actingAs($a)->patchJson($path.'/workflow-status',['workflow_status'=>'exception','expected_workflow_revision'=>1])->assertOk();
  $this->actingAs($b)->patchJson($path.'/workflow-status',['workflow_status'=>'resolve_exception','expected_workflow_revision'=>2])->assertOk();
  $this->actingAs($a)->patchJson($path.'/workflow-status',['workflow_status'=>'missing_info','expected_workflow_revision'=>3])->assertOk();
  $this->actingAs($b)->patchJson($path.'/workflow-status',['workflow_status'=>'pending_allocation','expected_workflow_revision'=>4])->assertOk();
  $this->assertSame('UNASSIGNED',$o->fresh()->routing_warehouse_code);
  $this->assertNull($o->fresh()->shipment->warehouse_id);
  $this->actingAs($reader)->patchJson($path.'/warehouse-route',['warehouse_code'=>'US-NY','expected_routing_revision'=>8])->assertForbidden();
  $this->actingAs($reader)->patchJson($path.'/workflow-status',['workflow_status'=>'exception','expected_workflow_revision'=>5])->assertForbidden();
  $this->actingAs($a)->patchJson('/customers/orders/'.$o->id,['city'=>'DENIED'])->assertForbidden();
  $this->actingAs($b)->patchJson($path.'/workflow-status',['workflow_status'=>'paid','expected_workflow_revision'=>5])->assertStatus(422);
  $this->actingAs($b)->patchJson($path.'/workflow-status',['workflow_status'=>'shipped','expected_workflow_revision'=>5])->assertStatus(409);
  $this->assertDatabaseCount('shipout_dispatch_attempts',0);
  $this->assertNull($o->fresh()->shipment->tracking_number);
  $this->assertDatabaseHas('audit_records',['entity_id'=>(string)$o->id,'actor_id'=>$a->id,'action'=>'order.warehouse_route_changed']);
  $this->assertDatabaseHas('audit_records',['entity_id'=>(string)$o->id,'actor_id'=>$b->id,'action'=>'order.core_status_changed']);
 }
}
