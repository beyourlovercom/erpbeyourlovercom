<?php

namespace Tests\Feature\Logistics;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Application\Xlwms\LocalTestDraftExercise;
use App\Modules\Logistics\Infrastructure\Models\Carrier;
use App\Modules\Logistics\Infrastructure\Models\CarrierService;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class XlwmsRealDraftT2SafetyTest extends TestCase
{
    use RefreshDatabase;

    private function testOrder(): Order
    {
        $w=Warehouse::query()->firstOrCreate(['code'=>'US-SM'],[
            'slug'=>'us-3sm','name'=>'Test Shimai','type'=>'warehouse','is_active'=>true,
        ]);
        $p=Product::query()->firstOrCreate(['sku'=>'VP676A'],[
            'name'=>'VP676A TEST','product_type'=>'simple','is_active'=>true,
        ]);
        WarehouseInventory::query()->updateOrCreate([
            'warehouse_id'=>$w->id,'product_id'=>$p->id,
        ],['quantity_on_hand'=>1,'quantity_reserved'=>0]);
        $c=Carrier::query()->firstOrCreate(['name'=>'USPS'],['is_active'=>true]);
        $svc=CarrierService::query()->firstOrCreate([
            'carrier_id'=>$c->id,'service_key'=>'xlwms-test',
        ],['service_value'=>'OMS-TEST-CHANNEL','is_active'=>true]);
        $o=Order::query()->create([
            'internal_order_number'=>'TEST-XLWMS-001',
            'erp_order_id'=>'TEST-XLWMS-001',
            'external_order_number'=>'TEST-XLWMS-001',
            'source_type'=>'manual_other',
            'order_kind'=>'customer',
            'order_date'=>'2026-10-26',
            'order_status'=>'pending_shipment',
        ]);
        $o->address()->create([
            'recipient_name'=>'API TEST DO NOT SHIP',
            'phone'=>'5550100001','email'=>'none@example.invalid',
            'country'=>'US','province_state'=>'CA','city'=>'Los Angeles',
            'address_line_1'=>'123 Test Only Lane','postal_code'=>'90001',
        ]);
        $o->items()->create([
            'product_id'=>$p->id,'product_summary'=>'TEST ONLY',
            'sku'=>'VP676A','quantity'=>1,'unit_price'=>0,'line_revenue'=>0,
        ]);
        $s=$o->shipment()->create([
            'shipment_status'=>'pending','warehouse_id'=>$w->id,
            'carrier'=>'USPS','service'=>'OMS-TEST-CHANNEL',
            'carrier_service_id'=>$svc->id,'carrier_selection_source'=>'manual',
        ]);
        $s->overseaReview()->create([
            'status'=>'approved','reviewed_warehouse_id'=>$w->id,
            'reviewed_at'=>now(),
        ]);
        WarehouseOpenApiConnection::query()->create([
            'warehouse_id'=>$w->id,'provider'=>'xlwms',
            'app_key_encrypted'=>'TEST-KEY',
            'app_secret_encrypted'=>'TEST-SECRET',
            'oms_warehouse_code'=>'OMS-TEST-WH',
            'last_verified_at'=>now(),
        ]);
        return $o->fresh();
    }

    private function fakeSuccess(): void
    {
        Http::preventStrayRequests();
        Http::fake(function($request) {
            if(str_contains($request->url(),'outboundOrder/detail'))
                return Http::response(['code'=>200,'data'=>[]]);
            if(str_contains($request->url(),'outboundOrder/pageList'))
                return Http::response(['code'=>200,'data'=>['records'=>[],'total'=>0]]);
            if(str_contains($request->url(),'logistics/channel/list'))
                return Http::response(['code'=>200,'data'=>[[
                    'channelCode'=>'OMS-TEST-CHANNEL','whCodes'=>['OMS-TEST-WH'],
                ]]]);
            if(str_contains($request->url(),'product/pagelist'))
                return Http::response(['code'=>200,'data'=>[
                    'records'=>[['sku'=>'VP676A','approveStatus'=>2]],
                ]]);
            if(str_contains($request->url(),'outboundOrder/create')) {
                $row=$request['data'][0];
                $ref=is_object($row)?$row->thirdOrderNo:$row['thirdOrderNo'];
                return Http::response(['code'=>200,'data'=>[[
                    'thirdOrderNo'=>$ref,'success'=>true,'orderNo'=>'SIMULATED-OMS-DRAFT',
                ]]]);
            }
            return Http::response(['code'=>400]);
        });
    }

    public function test_default_disabled_blocks_t2_without_network_or_attempt(): void
    {
        $o=$this->testOrder();
        config()->set('xlwms.t2_test_draft_enabled',false);
        $this->fakeSuccess();
        try {
            app(LocalTestDraftExercise::class)->execute($o->id);
            $this->fail('T2 must remain disabled by default.');
        } catch (\RuntimeException $e) {
            $this->assertSame('T2_DRAFT_NOT_ENABLED_OR_UNSAFE_ENVIRONMENT',$e->getMessage());
        }
        $this->assertDatabaseCount('xlwms_dispatch_attempts',0);
        Http::assertNothingSent();
    }

    public function test_exact_single_test_draft_uses_only_is_submit_zero_and_blocks_second_attempt(): void
    {
        $o=$this->testOrder();
        config()->set('xlwms.t2_test_draft_enabled',true);
        config()->set('xlwms.live_dispatch_enabled',false);
        $this->fakeSuccess();
        $result=app(LocalTestDraftExercise::class)->execute($o->id);
        $this->assertSame('draft_created_pending_cancel',$result['state']);
        $this->assertSame('SIMULATED-OMS-DRAFT',$result['oms_order_no']);
        $this->assertSame('pending_shipment',$o->fresh()->order_status->value);
        $this->assertSame('pending',$o->shipment()->first()->shipment_status);
        $this->assertSame('draft_created_pending_cancel',XlwmsDispatchAttempt::query()->sole()->state);
        Http::assertSent(fn($req): bool=>str_contains($req->url(),'outboundOrder/create')
            && (int)(is_object($req['data'][0])?$req['data'][0]->isSubmit:$req['data'][0]['isSubmit'])===0);
        Http::assertSentCount(6);
        try {
            app(LocalTestDraftExercise::class)->execute($o->id);
            $this->fail('Retry should never be permitted.');
        } catch (\RuntimeException $e) {
            $this->assertSame('T2_DRAFT_ATTEMPT_ALREADY_EXISTS_NEVER_RETRY',$e->getMessage());
        }
        Http::assertSentCount(6);
    }

    public function test_duplicate_manual_reference_blocks_without_create_and_without_attempt(): void
    {
        $o=$this->testOrder();
        config()->set('xlwms.t2_test_draft_enabled',true);
        Http::preventStrayRequests();
        Http::fake(function($req) {
            if(str_contains($req->url(),'outboundOrder/detail'))
                return Http::response(['code'=>200,'data'=>[]]);
            return Http::response(['code'=>200,'data'=>[
                'records'=>[['referOrderNo'=>'TEST-XLWMS-001','outboundOrderNo'=>'OLD']],
                'total'=>1,
            ]]);
        });
        try {
            app(LocalTestDraftExercise::class)->execute($o->id);
            $this->fail('Duplicate must stop before create.');
        } catch (\RuntimeException $e) {
            $this->assertSame('T2_TEST_REFERENCE_ALREADY_EXISTS_IN_OMS',$e->getMessage());
        }
        $this->assertDatabaseCount('xlwms_dispatch_attempts',0);
        Http::assertNotSent(fn($r):bool=>str_contains($r->url(),'outboundOrder/create'));
    }

    public function test_timeout_on_create_is_marked_uncertain_and_never_retried(): void
    {
        $o=$this->testOrder();
        config()->set('xlwms.t2_test_draft_enabled',true);
        $this->fakeSuccess();
        Http::fake(function($r) {
            if(str_contains($r->url(),'outboundOrder/create')) throw new \RuntimeException('Simulated timeout');
            if(str_contains($r->url(),'outboundOrder/detail'))
                return Http::response(['code'=>200,'data'=>[]]);
            if(str_contains($r->url(),'outboundOrder/pageList'))
                return Http::response(['code'=>200,'data'=>['records'=>[],'total'=>0]]);
            if(str_contains($r->url(),'logistics/channel/list'))
                return Http::response(['code'=>200,'data'=>[[
                    'channelCode'=>'OMS-TEST-CHANNEL','whCodes'=>['OMS-TEST-WH'],
                ]]]);
            return Http::response(['code'=>200,'data'=>[
                'records'=>[['sku'=>'VP676A','approveStatus'=>2]],
            ]]);
        });
        $result=app(LocalTestDraftExercise::class)->execute($o->id);
        $this->assertSame('draft_create_uncertain',$result['state']);
        $this->assertSame('OMS_TRANSPORT_UNCERTAIN',$result['error_code']);
        $this->assertDatabaseCount('xlwms_dispatch_attempts',1);
    }
}
