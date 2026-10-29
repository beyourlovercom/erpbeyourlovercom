<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\ShipOut\ShipOutTrackingApplyService;
use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutReadOnlyGateway;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** R7 explicitly opted-in LOCAL Woo integration, sandbox provider GET stub.
 * Never connects to real ShipOut or www. Runs against localhost Woo/AfterShip.
 */
final class ShipOutLocalWooVitaE2eTest extends TestCase
{
    use RefreshDatabase;

    public function test_dede_provider_tracking_to_erp_then_vita_manual_woo_aftership(): void
    {
        if (getenv('SHIPOUT_R7_REAL_LOCAL') !== '1') {
            $this->markTestSkipped('Local WordPress sandbox opt-in required');
        }
        $base = getenv('HOME').'/AI/uat/tracking-v2-local-wp-20261029/';
        $conn=json_decode(file_get_contents($base.'uat-connection.json'),true,512,JSON_THROW_ON_ERROR);
        $fixture=json_decode(file_get_contents($base.'r1r7-order.json'),true,512,JSON_THROW_ON_ERROR);
        $this->assertSame('http://127.0.0.1:18763',$conn['base_url']);
        $this->assertCount(2,$fixture['woo_line_ids']);
        config([
            'services.woocommerce.url'=>$conn['base_url'],
            'services.woocommerce.consumer_key'=>$conn['consumer_key'],
            'services.woocommerce.consumer_secret'=>$conn['consumer_secret'],
            'services.woocommerce.connection_key'=>$conn['connection_key'],
            'tracking_sync_v2.manual_mode'=>'canary',
            'tracking_sync_v2.immediate_enabled'=>false,
            'tracking_sync_v2.periodic_enabled'=>false,
            'shipout.sandbox_read_enabled'=>true,
            'shipout.sandbox_tracking_apply_enabled'=>true,
            'shipout.sandbox_warehouse_id'=>'TEST-W',
            'shipout.sandbox_shipped_time_timezone'=>'UTC', // SYNTHETIC PROVIDER FIXTURE ONLY
        ]);
        $this->app->bind(ShipOutReadOnlyGateway::class,
            fn () => new ShipOutReadOnlyGateway('r7-test-key','r7-test-secret','r7-test-token'));
        $wh=Warehouse::query()->updateOrCreate(['code'=>'US-LA'],[
            'slug'=>'us-la','name'=>'MorePlus R7 Local','type'=>'warehouse',
            'is_active'=>true,'priority'=>10,
        ]);
        $dede=$this->actor('role_preset_logistics',['logistics.view','logistics.operate']);
        $vita=$this->actor('role_preset_operations',['logistics.view','orders.view','sync.view']);
        $order=Order::query()->create([
            'internal_order_number'=>'LOCAL-SHIPOUT-R7-WOO',
            'erp_order_id'=>'007-1029-c99999',
            'source_type'=>'woocommerce',
            'order_kind'=>'customer',
            'external_order_number'=>'WC-'.$fixture['order_id'],
            'order_date'=>'2026-10-29',
            'order_status'=>'pending_shipment',
            'currency'=>'USD',
        ]);
        $order->externalReferences()->create([
            'provider'=>'woocommerce',
            'connection_key'=>$conn['connection_key'],
            'reference'=>(string)$fixture['order_id'],
        ]);
        foreach ($fixture['woo_line_ids'] as $index=>$lineId) {
            $order->items()->create([
                'woocommerce_line_item_id'=>(int)$lineId,
                'sku'=>'SHIPOUT-R7-LINE-'.($index+1),
                'product_summary'=>'Local Only ShipOut UAT '.($index+1),
                'quantity'=>1,'unit_price'=>'12.00','line_revenue'=>'12.00',
            ]);
        }
        $order->financials()->create([
            'currency'=>'USD','provider'=>'woocommerce',
            'provider_reference'=>(string)$fixture['order_id'],
            'provider_order_status'=>'processing',
            'provider_modified_at'=>now(),
            'payment_method'=>'woocommerce_payments',
            'payment_transaction_id'=>'LOCAL-SHIPOUT-R7-UAT-PAID',
            'paid_at'=>now(),'provider_order_total'=>'24.00',
            'revenue_provenance'=>'provider_import',
            'completeness_status'=>'INCOMPLETE',
        ]);
        $shipment=$order->shipment()->create([
            'warehouse_id'=>$wh->id,
            'warehouse_selection_source'=>'manual',
            'shipment_status'=>'pending','carrier'=>'USPS',
        ]);
        OverseaWarehouseReview::query()->create([
            'order_id'=>$order->id,
            'submitted_warehouse_id'=>$wh->id,
            'reviewed_warehouse_id'=>$wh->id,
            'status'=>'approved',
            'reviewed_by'=>$dede->id,'reviewed_at'=>now(),
        ]);
        DB::table('shipout_dispatch_attempts')->insert([
            'order_id'=>$order->id,'order_no'=>'C99999-007',
            'provider_warehouse_id'=>'TEST-W',
            'payload_hash'=>str_repeat('a',64),
            'status'=>'acknowledged',
            'provider_order_id'=>'R7-PROVIDER-SIMULATED-123',
            'created_at'=>now(),'updated_at'=>now(),
        ]);

        $fakeGet=fn($req) => str_contains($req->url(),'/queryListV2?')
            ? Http::response(['result'=>'OK','data'=>[
                'total'=>1,'records'=>[[
                    'orderId'=>'R7-PROVIDER-SIMULATED-123',
                    'orderNO'=>'C99999-007',
                    'status'=>4,
                    'shipmentList'=>[],
                ]],
            ]],200)
            : Http::response(['result'=>'OK','data'=>[
                'orderId'=>'R7-PROVIDER-SIMULATED-123',
                'orderNO'=>'C99999-007','status'=>4,
                'orderShipments'=>[[
                    'warehouseId'=>'TEST-W','shipmentId'=>'R7-ONE',
                    'outboundStatus'=>4,'labelStatus'=>3,
                    'trackingNumber'=>'9400SHIPOUTR7LOCAL1234',
                    'shippedTime'=>'2026-10-29 10:00:00',
                    'carrierId'=>1,
                ]],
            ]],200);
        Http::fake(['https://opendev.shipout.com/api/*'=>$fakeGet]);
        // Only the isolated localhost WordPress test may pass through.
        // All other unfaked outbound requests remain prohibited.
        Http::preventStrayRequests();
        Http::allowStrayRequests(['http://127.0.0.1:18763/*']);

        // Dede's provider readback+ERP write does NOT touch localhost Woo.
        $gateway=app(WooCommerceTrackingGateway::class);
        $this->assertSame('processing',$gateway->inspectOrderStatus((int)$fixture['order_id']));
        $this->assertFalse($gateway->inspectTracking((int)$fixture['order_id'])['has_tracking']);
        $r=app(ShipOutTrackingApplyService::class)->apply(
            $order->id,'9400SHIPOUTR7LOCAL1234',$dede,
        );
        $this->assertSame('erp_shipment_updated',$r['status']);
        $this->assertSame('pending_vita',$r['woo_sync']);
        $this->assertSame('shipped',$order->fresh()->shipment->shipment_status);
        $this->assertSame('9400SHIPOUTR7LOCAL1234',$order->fresh()->shipment->tracking_number);
        $this->assertDatabaseHas('shipout_dispatch_attempts',[
            'order_id'=>$order->id,'status'=>'shipped_applied',
        ]);
        $this->assertDatabaseHas('manual_syncs',[
            'order_id'=>$order->id,'website_sync_status'=>'pending',
        ]);
        $this->assertFalse($gateway->inspectTracking((int)$fixture['order_id'])['has_tracking']);
        $this->assertSame('processing',$gateway->inspectOrderStatus((int)$fixture['order_id']));

        // Dede cannot impersonate Vita's Woo outbound authorization.
        $this->actingAs($dede)->post('/logistics/tracking-outbound/selected',[
            'order_ids'=>[$order->id],
        ])->assertForbidden();
        $this->assertFalse($gateway->inspectTracking((int)$fixture['order_id'])['has_tracking']);

        config(['tracking_sync_v2.manual_canary_order_ids'=>(string)$order->id]);
        $this->actingAs($vita)->post('/logistics/tracking-outbound/selected',[
            'order_ids'=>[$order->id],
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('9400SHIPOUTR7LOCAL1234',
            $gateway->inspectTracking((int)$fixture['order_id'])['tracking_number']);
        $this->assertSame('completed',$gateway->inspectOrderStatus((int)$fixture['order_id']));
        $this->assertDatabaseHas('manual_syncs',[
            'order_id'=>$order->id,'website_sync_status'=>'completed',
        ]);
        $this->assertSame(2, DB::table('sync_outbox')->count());

        // Explicit repeat is read-back/idempotent, not two new tracking rows.
        $this->actingAs($vita)->post('/logistics/tracking-outbound/selected',[
            'order_ids'=>[$order->id],
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(2, DB::table('sync_outbox')->count());
        $this->assertSame('9400SHIPOUTR7LOCAL1234',
            $gateway->inspectTracking((int)$fixture['order_id'])['tracking_number']);
    }

    private function actor(string $roleKey, array $permissions): User
    {
        $role=Role::query()->firstOrCreate(['key'=>$roleKey],['label'=>$roleKey]);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key'=>$permission],['label'=>$permission]);
        }
        $user=User::factory()->create(['is_active'=>true,'permission_mode'=>'role']);
        $user->roles()->attach($role);
        return $user;
    }
}
