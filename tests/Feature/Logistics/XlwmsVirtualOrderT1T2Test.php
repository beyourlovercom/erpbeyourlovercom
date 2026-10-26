<?php

namespace Tests\Feature\Logistics;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Application\Xlwms\SingleOrderDispatch;
use App\Modules\Logistics\Application\Xlwms\TestDraftPlan;
use App\Modules\Logistics\Infrastructure\Models\Carrier;
use App\Modules\Logistics\Infrastructure\Models\CarrierService;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsOrderGateway;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class XlwmsVirtualOrderT1T2Test extends TestCase
{
    use RefreshDatabase;

    private function testOrder(): Order
    {
        $w = Warehouse::query()->firstOrCreate(['code' => 'US-SM'], [
            'slug'=>'us-3sm', 'name'=>'TEST US-SM', 'type'=>'warehouse', 'is_active'=>true,
        ]);
        $c = Carrier::query()->firstOrCreate(['name'=>'USPS'],['is_active'=>true]);
        $svc = CarrierService::query()->firstOrCreate([
            'carrier_id'=>$c->id, 'service_key'=>'xlwms-test',
        ],['service_value'=>'OMS-TEST-CHANNEL','is_active'=>true]);
        $p = Product::query()->firstOrCreate(['sku'=>'VP676A'],[
            'name'=>'T1 T2 simulated test item','product_type'=>'simple','is_active'=>true,
        ]);
        WarehouseInventory::query()->updateOrCreate([
            'warehouse_id'=>$w->id,'product_id'=>$p->id,
        ],['quantity_on_hand'=>2,'quantity_reserved'=>0]);

        $o = Order::query()->create([
            'internal_order_number'=>'TEST-XLWMS-001',
            'erp_order_id'=>'TEST-XLWMS-001',
            'source_type'=>'manual_other','order_kind'=>'customer',
            'external_order_number'=>'TEST-XLWMS-001',
            'order_date'=>'2026-10-26','order_status'=>'pending_shipment',
        ]);
        $o->address()->create([
            'recipient_name'=>'Simulation No Shipment',
            'phone'=>'5550100001', 'email'=>'nobody@example.invalid',
            'country'=>'US', 'province_state'=>'CA', 'city'=>'Demo City',
            'address_line_1'=>'123 Synthetic Test Lane', 'postal_code'=>'90001',
        ]);
        $o->items()->create([
            'product_id'=>$p->id,'product_summary'=>'T1 Test Only',
            'sku'=>'VP676A','quantity'=>1,'unit_price'=>0,'line_revenue'=>0,
        ]);
        $shipment = $o->shipment()->create([
            'shipment_status'=>'pending','warehouse_id'=>$w->id,
            'carrier'=>'USPS','service'=>'OMS-TEST-CHANNEL',
            'carrier_service_id'=>$svc->id,'carrier_selection_source'=>'manual',
        ]);
        $shipment->overseaReview()->create([
            'status'=>'approved', 'reviewed_warehouse_id'=>$w->id,'reviewed_at'=>now(),
        ]);
        WarehouseOpenApiConnection::query()->create([
            'warehouse_id'=>$w->id,'provider'=>'xlwms',
            'app_key_encrypted'=>'SIMULATED-KEY-NOT-REAL',
            'app_secret_encrypted'=>'SIMULATED-SECRET-NOT-REAL',
            'oms_warehouse_code'=>'OMS-TEST-WAREHOUSE',
        ]);
        return $o->fresh();
    }

    private function fakeProviderForSuccess(): void
    {
        Http::preventStrayRequests();
        Http::fake(function($request) {
            if(str_contains($request->url(),'outboundOrder/detail')) {
                return Http::response(['code'=>200,'data'=>[]]);
            }
            if(str_contains($request->url(),'outboundOrder/pageList')) {
                return Http::response(['code'=>200,'data'=>['records'=>[],'total'=>0]]);
            }
            if(str_contains($request->url(),'logistics/channel/list')) {
                return Http::response(['code'=>200,'data'=>[
                    ['channelCode'=>'OMS-TEST-CHANNEL','whCodes'=>['OMS-TEST-WAREHOUSE']],
                ]]);
            }
            if(str_contains($request->url(),'product/pagelist')) {
                return Http::response(['code'=>200,'data'=>[
                    'records'=>[['sku'=>'VP676A','approveStatus'=>2]],
                ]]);
            }
            if(str_contains($request->url(),'outboundOrder/create')) {
                $item = $request['data'][0];
                $number = is_object($item) ? $item->thirdOrderNo : $item['thirdOrderNo'];
                return Http::response(['code'=>200,'data'=>[[
                    'thirdOrderNo'=>$number,'success'=>true,
                    'orderNo'=>'SIMULATED-OMS-DRAFT-NOT-REAL',
                ]]]);
            }
            return Http::response(['code'=>400]);
        });
    }

    public function test_t1_virtual_order_submission_simulator_has_one_product_and_safely_reports_receipt(): void
    {
        $o=$this->testOrder();
        $this->fakeProviderForSuccess();
        config()->set('xlwms.live_dispatch_enabled',true);
        config()->set('xlwms.live_order_id',$o->id);
        $service=app(SingleOrderDispatch::class);
        $preview=$service->preview($o->id);
        $this->assertTrue($preview['ready_for_remote_check']);
        $this->assertSame([['sku'=>'VP676A','quantity'=>1]],$preview['payload']['productList']);
        $this->assertSame('TEST-XLWMS-001',$preview['payload']['referOrderNo']);
        $this->assertSame(1,$preview['payload']['isSubmit']);

        $result=$service->dispatch($o->id,$preview['payload_hash'],3);
        $this->assertSame('submitted_oms',$result['state']);
        $this->assertSame('SIMULATED-OMS-DRAFT-NOT-REAL',$result['oms_order_no']);
        $this->assertSame('pending_shipment',$o->fresh()->order_status->value);
        $this->assertSame('pending',$o->shipment()->first()->shipment_status);
        Http::assertSent(fn($req)=>str_contains($req->url(),'outboundOrder/create'));
        $this->assertNotNull($result['last_checked_at']);
        // One dispatch attempt is terminal. Never send the same test order twice.
        $this->expectException(HttpException::class);
        $service->dispatch($o->id,$preview['payload_hash'],3);
    }

    public function test_t1_manual_oms_duplicate_reference_is_blocked_without_outbound_create(): void
    {
        $o=$this->testOrder();
        Http::preventStrayRequests();
        Http::fake(function($r) {
            if(str_contains($r->url(),'outboundOrder/detail')) {
                return Http::response(['code'=>200,'data'=>[]]);
            }
            return Http::response(['code'=>200,'data'=>['records'=>[[
                'referOrderNo'=>'TEST-XLWMS-001',
                'outboundOrderNo'=>'EXISTING-MANUAL-ORDER',
            ]],'total'=>1]]);
        });
        config()->set('xlwms.live_dispatch_enabled',true);
        config()->set('xlwms.live_order_id',$o->id);
        $service=app(SingleOrderDispatch::class);
        $preview=$service->preview($o->id);
        $result=$service->dispatch($o->id,$preview['payload_hash'],3);
        $this->assertSame('blocked_existing_oms_order',$result['state']);
        $this->assertSame('OMS_DUPLICATE_ORDER',$result['failure_code']);
        $this->assertSame('EXISTING-MANUAL-ORDER',$result['oms_order_no']);
        Http::assertNotSent(fn($r)=>str_contains($r->url(),'outboundOrder/create'));
    }

    public function test_t2_locally_prepares_draft_without_any_network_or_persistent_oms_record(): void
    {
        $o=$this->testOrder();
        Http::preventStrayRequests();
        Http::fake();
        $plan=app(TestDraftPlan::class)->prepare($o->id);
        $this->assertSame('dry_run_only',$plan['mode']);
        $this->assertSame('TEST-XLWMS-001',$plan['test_order']);
        $this->assertSame(0,$plan['payload']['isSubmit']);
        $this->assertSame([['sku'=>'VP676A','quantity'=>1]],$plan['payload']['productList']);
        $this->assertFalse($plan['remote_post_permitted']);
        $this->assertFalse($plan['remote_created']);
        $this->assertFalse($plan['warehouse_fulfillment_permitted']);
        $this->assertCount(64, str_split($plan['draft_payload_hash']));
        $this->assertDatabaseCount('xlwms_dispatch_attempts',0);
        Http::assertNothingSent();

        // The existing gateway must NOT be used as a shortcut to make draft
        // because its contract allows only reviewed, real isSubmit=1 orders.
        $conn=WarehouseOpenApiConnection::query()->sole();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('OMS_SUBMIT_CONTRACT_INVALID');
        app(XlwmsOrderGateway::class)->createSingle($conn,$plan['payload']);
    }

    public function test_t2_rejects_real_customer_order_without_test_prefix(): void
    {
        $o=$this->testOrder();
        $o->erp_order_id='007-1023-C41724';
        $o->save();
        Http::preventStrayRequests();
        Http::fake();
        $this->expectException(HttpException::class);
        app(TestDraftPlan::class)->prepare($o->id);
    }
}
