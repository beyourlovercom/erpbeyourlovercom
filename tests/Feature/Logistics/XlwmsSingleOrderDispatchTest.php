<?php

namespace Tests\Feature\Logistics;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Logistics\Application\Xlwms\SingleOrderDispatch;
use App\Modules\Logistics\Application\Xlwms\WarehouseBatchDispatch;
use App\Modules\Logistics\Infrastructure\Models\Carrier;
use App\Modules\Logistics\Infrastructure\Models\CarrierService;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsInventorySnapshot;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class XlwmsSingleOrderDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Legacy canned catalogue responses are synthetic and omit their
        // provider category. Separate tests below enforce the actual type 2.
        config()->set('xlwms.us_sm_require_no_shipping_service', false);
        config()->set('xlwms.us_sm_oms_logistics_channel','No_Shipping_Service');
    }

    private function orderFixture(): Order
    {
        $w=Warehouse::query()->firstOrCreate(['code'=>'US-SM'],[
            'slug'=>'us-3sm','name'=>'US Shimai','type'=>'warehouse','is_active'=>true,
        ]);
        $carrier=Carrier::query()->firstOrCreate(['name'=>'USPS'],['is_active'=>true]);
        $service=CarrierService::query()->firstOrCreate([
            'carrier_id'=>$carrier->id,'service_key'=>'oms-standard'
        ],['service_value'=>'OMS-STANDARD','is_active'=>true]);
        $o=Order::query()->create([
            'internal_order_number'=>'TEST-SHIP-'.uniqid(),
            'erp_order_id'=>'ERP-SINGLE-'.uniqid(),
            'source_type'=>'manual_other','order_kind'=>'customer',
            'external_order_number'=>'WOOCOMM-'.uniqid(),
            'order_date'=>'2026-10-22','order_status'=>'pending_shipment',
        ]);
        $o->address()->create([
            'recipient_name'=>'Test Buyer','phone'=>'555010001','email'=>'example@test.invalid',
            'country'=>'US','province_state'=>'CA','city'=>'Los Angeles',
            'address_line_1'=>'1 Test Road','postal_code'=>'90001'
        ]);
        $product = Product::query()->firstOrCreate(['sku'=>'SAME-SKU'], [
            'name'=>'Test Product','product_type'=>'simple','is_active'=>true,
        ]);
        WarehouseInventory::query()->updateOrCreate([
            'warehouse_id'=>$w->id,'product_id'=>$product->id
        ],['quantity_on_hand'=>10,'quantity_reserved'=>0]);
        $snapshot = XlwmsInventorySnapshot::query()->create([
            'warehouse_id'=>$w->id, 'wh_code'=>'OMS-US-SM', 'checksum'=>hash('sha256', 'UAT-SNAPSHOT-'.$o->id),
            'status'=>'applied', 'fetched_at'=>now(), 'applied_at'=>now(),
            'provider_sku_count'=>1, 'mapped_sku_count'=>1, 'unmatched_sku_count'=>0,
            'blocked_sku_count'=>0, 'summary'=>['v2'=>true, 'v2_applied'=>true, 'oms_rows'=>1],
        ]);
        $snapshot->rows()->create([
            'provider_sku'=>'SAME-SKU', 'product_id'=>$product->id,
            'resolution_status'=>'matched', 'oms_total'=>10, 'oms_available'=>10,
            'oms_locked'=>0, 'oms_in_transit'=>0,
        ]);
        $o->items()->create(['product_id'=>$product->id,'product_summary'=>'Test','sku'=>'SAME-SKU','quantity'=>1,'unit_price'=>10,'line_revenue'=>10]);
        $shipment=$o->shipment()->create([
            'shipment_status'=>'pending','warehouse_id'=>$w->id,
            'carrier'=>'USPS','service'=>'OMS-STANDARD',
            'carrier_service_id'=>$service->id,'carrier_selection_source'=>'manual',
        ]);
        $shipment->overseaReview()->create([
            'status'=>'approved','reviewed_warehouse_id'=>$w->id,'reviewed_at'=>now(),
        ]);
        WarehouseOpenApiConnection::query()->updateOrCreate(['warehouse_id'=>$w->id,'provider'=>'xlwms'],[
            'app_key_encrypted'=>'TEST-APPKEY-1234',
            'app_secret_encrypted'=>'TEST-SECRET-1234',
            'oms_warehouse_code'=>'OMS-US-SM',
            'last_verified_at'=>now(),
        ]);
        return $o->fresh();
    }

    public function test_owner_selected_no_shipping_service_creates_draft_without_label_fields(): void
    {
        $o=$this->orderFixture();
        config()->set('xlwms.us_sm_require_no_shipping_service', true);
        $preview=app(SingleOrderDispatch::class)->preview($o->id);
        $this->assertSame([], $preview['blockers']);
        $this->assertSame('No_Shipping_Service', $preview['payload']['logisticsChannel']);
        $this->assertSame(0, $preview['payload']['isSubmit']);
        $this->assertArrayNotHasKey('shippingLabel', $preview['payload']);
        $this->assertArrayNotHasKey('logisticsTrackNo', $preview['payload']);
        $this->assertSame('pending', $o->fresh()->shipment->shipment_status);
        config()->set('xlwms.us_sm_oms_logistics_channel','Service with the lowest price');
        $bad=app(SingleOrderDispatch::class)->preview($o->id);
        $this->assertContains('US_SM_NO_SHIPPING_CHANNEL_REQUIRED',$bad['blockers']);
        Http::fake();Http::assertNothingSent();
    }

    public function test_real_type_two_no_shipping_provider_channel_is_required_before_outbound_create(): void
    {
        $o=$this->orderFixture();
        $w=WarehouseOpenApiConnection::query()->where('warehouse_id',$o->shipment->warehouse_id)->firstOrFail();
        $gw=app(\App\Modules\Logistics\Infrastructure\Xlwms\XlwmsOrderGateway::class);
        Http::preventStrayRequests();
        Http::fake(fn ($req)=>str_contains($req->url(),'logistics/channel/list')
            ? Http::response(['code'=>200,'data'=>[['channelCode'=>'No_Shipping_Service','channelName'=>'无需物流服务','channelType'=>2,'carrierName'=>'系统默认渠道','whCodes'=>[]]]])
            : Http::response(['code'=>200,'data'=>['records'=>[['sku'=>'SAME-SKU','approveStatus'=>2]]]]));
        $gw->assertCatalogReady($w,'OMS-US-SM','No_Shipping_Service',['SAME-SKU'],true);
        Http::assertNotSent(fn($req)=>str_contains($req->url(),'outboundOrder/create'));
    }

    public function test_wrong_special_channel_or_type_is_rejected_without_fallback(): void
    {
        $o=$this->orderFixture();
        $w=WarehouseOpenApiConnection::query()->where('warehouse_id',$o->shipment->warehouse_id)->firstOrFail();
        $gw=app(\App\Modules\Logistics\Infrastructure\Xlwms\XlwmsOrderGateway::class);
        Http::preventStrayRequests();
        foreach ([['channelCode'=>'Service with the lowest price','channelName'=>'自动选取价格最低的渠道','channelType'=>1],
                  ['channelCode'=>'Upload_Shipping_Label','channelName'=>'USPS上传物流面单','channelType'=>3],
                  ['channelCode'=>'No_Shipping_Service','channelName'=>'无需物流服务','channelType'=>3],
                  ['channelCode'=>'No_Shipping_Service','channelName'=>'上传物流面单','channelType'=>2]] as $record) {
            Http::fake(fn ($req)=>Http::response(['code'=>200,'data'=>[array_merge($record,['whCodes'=>[]])]]));
            try {$gw->assertCatalogReady($w,'OMS-US-SM','No_Shipping_Service',['SAME-SKU'],true);
                $this->fail('Unverified channel must not dispatch');}
            catch (\RuntimeException $e) {
                $this->assertContains($e->getMessage(), ['US_SM_NO_SHIPPING_CHANNEL_UNAVAILABLE','OMS_CHANNEL_NOT_AVAILABLE']);
            }
        }
        Http::assertNotSent(fn($req)=>str_contains($req->url(),'outboundOrder/create'));
    }

    public function test_no_shipping_channel_does_not_claim_known_last_mile_carrier(): void
    {
        $o=$this->orderFixture();
        $o->shipment()->update(['carrier'=>'FEDEX','service'=>'Priority']);
        config()->set('xlwms.us_sm_require_no_shipping_service',true);
        $p=app(SingleOrderDispatch::class)->preview($o->id);
        $this->assertSame([], $p['blockers']);
        $this->assertSame('No_Shipping_Service',$p['payload']['logisticsChannel']);
        $this->assertSame(0,$p['payload']['isSubmit']);
        $this->assertSame('pending',$o->fresh()->shipment->shipment_status);
        Http::fake();Http::assertNothingSent();
    }

    public function test_inline_preflight_is_permissioned_readonly_and_excludes_recipient_details(): void
    {
        $o=$this->orderFixture();
        $role=\App\Models\Role::query()->firstOrCreate(
            ['key'=>'xlwms_preflight_viewer'],['label'=>'Viewer']
        );
        $p=\App\Models\Permission::query()->firstOrCreate(
            ['key'=>'logistics.view'],['label'=>'View Logistics']
        );
        $role->permissions()->syncWithoutDetaching([$p->id]);
        $viewer=\App\Models\User::factory()->create(['is_active'=>true]);
        $viewer->roles()->attach($role);
        Http::preventStrayRequests();
        Http::fake();
        $result=$this->actingAs($viewer)->getJson(
            '/logistics/oversea/us-3sm/orders/'.$o->id.'/xlwms/preflight'
        )->assertOk()
            ->assertJsonPath('order_id',$o->id)
            ->assertJsonPath('ready',true)
            ->assertJsonPath('already_attempted',false)
            ->assertJsonPath('oms_warehouse','OMS-US-SM')
            ->assertJsonPath('items.0.sku','SAME-SKU');
        $json=$result->getContent();
        $this->assertStringNotContainsString('Test Buyer',$json);
        $this->assertStringNotContainsString('1 Test Road',$json);
        $this->assertStringNotContainsString('TEST-SECRET',$json);
        $this->assertDatabaseCount('xlwms_dispatch_attempts',0);
        Http::assertNothingSent();
    }

    public function test_local_preflight_has_exact_provider_fields_but_no_network(): void
    {
        $o=$this->orderFixture();
        Http::fake();
        $p=app(SingleOrderDispatch::class)->preview($o->id);
        $this->assertSame([], $p['blockers']);
        $this->assertTrue($p['ready_for_remote_check']);
        $this->assertSame('OMS-US-SM', $p['payload']['whCode']);
        $this->assertSame('No_Shipping_Service', $p['payload']['logisticsChannel']);
        $this->assertSame('OMS-STANDARD', $o->fresh()->shipment->service);
        $this->assertSame('USPS', $o->fresh()->shipment->carrier);
        $this->assertSame('SAME-SKU', $p['payload']['productList'][0]['sku']);
        $this->assertSame(0, $p['payload']['isSubmit']);
        $this->assertSame('BYL-ERP-'.$o->id, $p['payload']['thirdOrderNo']);
        Http::assertNothingSent();
    }

    public function test_dispatch_is_hard_off_by_default_and_never_calls_oms(): void
    {
        $o=$this->orderFixture();
        config()->set('xlwms.live_dispatch_enabled',false);
        Http::fake();
        $s=app(SingleOrderDispatch::class);
        $p=$s->preview($o->id);
        try {
            $s->dispatch($o->id,$p['payload_hash'],3);
            $this->fail('Expected deliberate live-dispatch gate');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(423,$e->getStatusCode());
        }
        $this->assertDatabaseCount('xlwms_dispatch_attempts',0);
        Http::assertNothingSent();
    }

    public function test_global_live_switch_without_exact_owner_order_still_cannot_send(): void
    {
        $o=$this->orderFixture();
        config()->set('xlwms.live_dispatch_enabled',true);
        config()->set('xlwms.live_order_id',0);
        Http::fake();
        $s=app(SingleOrderDispatch::class);
        $p=$s->preview($o->id);
        try {
            $s->dispatch($o->id,$p['payload_hash'],3);
            $this->fail('Expected specific order allowlist denial');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(423,$e->getStatusCode());
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('xlwms_dispatch_attempts',0);
    }

    public function test_duplicate_woocommerce_order_in_oms_blocks_creation(): void
    {
        $o=$this->orderFixture();
        config()->set('xlwms.live_dispatch_enabled',true);
        config()->set('xlwms.live_order_id',$o->id);
        Http::fake(fn($req)=>Http::response([
            'code'=>200,'data'=>[['platformOrderNo'=>$o->external_order_number,'outboundOrderNo'=>'OMS-EXISTS']],
        ]));
        $s=app(SingleOrderDispatch::class);
        $p=$s->preview($o->id);
        $result=$s->dispatch($o->id,$p['payload_hash'],3);
        $this->assertSame('blocked_existing_oms_order',$result['state']);
        $this->assertSame('OMS-EXISTS',$result['oms_order_no']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn($r)=>str_contains($r->url(),'outboundOrder/create'));
        $this->assertSame('pending', $o->shipment()->first()->shipment_status);
    }

    public function test_existing_manually_created_oms_order_by_reference_blocks_real_create(): void
    {
        $o=$this->orderFixture();
        $o->external_order_number='C41704-007';
        $o->save();
        config()->set('xlwms.live_dispatch_enabled',true);
        config()->set('xlwms.live_order_id',$o->id);
        Http::fake(function($req) {
            if(str_contains($req->url(),'outboundOrder/detail')) {
                return Http::response(['code'=>200,'data'=>[]]);
            }
            if(str_contains($req->url(),'outboundOrder/pageList')) {
                return Http::response(['code'=>200,'data'=>['records'=>[[
                    'referOrderNo'=>'C41704-007','outboundOrderNo'=>'OBS0272610230RS',
                    'sku'=>'DIFFERENT-SKU',
                ]],'total'=>1]]);
            }
            return Http::response(['code'=>500]);
        });
        $s=app(SingleOrderDispatch::class);
        $p=$s->preview($o->id);
        $result=$s->dispatch($o->id,$p['payload_hash'],3);
        $this->assertSame('blocked_existing_oms_order',$result['state']);
        $this->assertSame('OMS_DUPLICATE_ORDER',$result['failure_code']);
        $this->assertSame('referOrderNo',$result['duplicate_match_field']);
        $this->assertSame('OBS0272610230RS',$result['oms_order_no']);
        $this->assertNotNull($result['last_checked_at']);
        Http::assertSentCount(3);
        Http::assertNotSent(fn($req)=>str_contains($req->url(),'outboundOrder/create'));
        $this->assertSame('pending_shipment',$o->fresh()->order_status->value);
        $this->assertFalse($s->preview($o->id)['ready_for_remote_check']);
    }

    public function test_paginated_manual_reference_is_checked_beyond_first_page(): void
    {
        $o=$this->orderFixture();
        $o->external_order_number='C41705-007';
        $o->save();
        config()->set('xlwms.live_dispatch_enabled',true);
        config()->set('xlwms.live_order_id',$o->id);
        Http::fake(function($req) {
            if(str_contains($req->url(),'outboundOrder/detail')) {
                return Http::response(['code'=>200,'data'=>[]]);
            }
            if(str_contains($req->url(),'outboundOrder/pageList')) {
                $page=(int) (is_object($req['data']) ? $req['data']->page : $req['data']['page']);
                return Http::response(['code'=>200,'data'=>[
                    'records'=>$page===1
                        ? array_fill(0,100,['referOrderNo'=>'OTHER'])
                        : [['referOrderNo'=>'C41705-007','outboundOrderNo'=>'OMS-SECOND-PAGE']],
                    'total'=>101,
                ]]);
            }
            return Http::response(['code'=>500]);
        });
        $p=app(SingleOrderDispatch::class)->preview($o->id);
        $r=app(SingleOrderDispatch::class)->dispatch($o->id,$p['payload_hash'],3);
        $this->assertSame('blocked_existing_oms_order',$r['state'],json_encode($r));
        $this->assertSame('OMS-SECOND-PAGE',$r['oms_order_no']);
        Http::assertSentCount(4);
        Http::assertNotSent(fn($req)=>str_contains($req->url(),'outboundOrder/create'));
    }

    public function test_unreadable_oms_list_blocks_submit_instead_of_falsely_claiming_no_duplicate(): void
    {
        $o=$this->orderFixture();
        config()->set('xlwms.live_dispatch_enabled',true);
        config()->set('xlwms.live_order_id',$o->id);
        Http::fake(function($req) {
            if(str_contains($req->url(),'outboundOrder/detail')) {
                return Http::response(['code'=>200,'data'=>[]]);
            }
            if(str_contains($req->url(),'outboundOrder/pageList')) {
                return Http::response(['code'=>200,'data'=>['unrecognized'=>'shape']]);
            }
            return Http::response(['code'=>500]);
        });
        $p=app(SingleOrderDispatch::class)->preview($o->id);
        $r=app(SingleOrderDispatch::class)->dispatch($o->id,$p['payload_hash'],3);
        $this->assertSame('blocked_preflight_error',$r['state']);
        $this->assertSame('OMS_PAGE_RESPONSE_INVALID',$r['failure_code']);
        Http::assertNotSent(fn($req)=>str_contains($req->url(),'outboundOrder/create'));
    }

    public function test_single_order_success_calls_detail_then_create_once_and_blocks_retry(): void
    {
        $o=$this->orderFixture();
        config()->set('xlwms.live_dispatch_enabled',true);
        config()->set('xlwms.live_order_id',$o->id);
        Http::fake(function($req) use ($o) {
            if(str_contains($req->url(),'outboundOrder/detail'))return Http::response(['code'=>200,'data'=>[]]);
            if(str_contains($req->url(),'outboundOrder/pageList'))return Http::response([
                'code'=>200,'data'=>['records'=>[],'total'=>0],
            ]);
            if(str_contains($req->url(),'logistics/channel/list'))return Http::response(['code'=>200,'data'=>[
                ['channelCode'=>'No_Shipping_Service','whCodes'=>['OMS-US-SM']],
            ]]);
            if(str_contains($req->url(),'product/pagelist'))return Http::response(['code'=>200,'data'=>[
                'records'=>[['sku'=>'SAME-SKU','approveStatus'=>2]],
            ]]);
            return Http::response(['code'=>200,'data'=>[[
                'thirdOrderNo'=>'BYL-ERP-'.$o->id,'success'=>true,'orderNo'=>'OMS-FIRST',
            ]]]);
        });
        $s=app(SingleOrderDispatch::class);
        $p=$s->preview($o->id);
        $r=$s->dispatch($o->id,$p['payload_hash'],3);
        $this->assertSame('oms_draft_created',$r['state']);
        $this->assertSame('OMS-FIRST',$r['oms_order_no']);
        Http::assertSentCount(6);
        Http::assertSent(fn($req)=>str_contains($req->url(),'outboundOrder/create')
            && (int) (is_object($req['data'][0]) ? $req['data'][0]->isSubmit : $req['data'][0]['isSubmit']) === 0
            && (is_object($req['data'][0]) ? $req['data'][0]->thirdOrderNo : $req['data'][0]['thirdOrderNo']) === 'BYL-ERP-'.$o->id
            && (is_object($req['data'][0]) ? $req['data'][0]->logisticsChannel : $req['data'][0]['logisticsChannel']) === 'No_Shipping_Service');
        $this->assertDatabaseCount('xlwms_dispatch_attempts',1);
        $this->assertSame('pending',$o->shipment()->first()->shipment_status);
        $this->assertSame('pending_shipment',$o->fresh()->order_status->value);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $s->dispatch($o->id,$p['payload_hash'],3);
    }

    public function test_uncertain_transport_does_not_retry_and_requires_manual_reconcile(): void
    {
        $o=$this->orderFixture();
        config()->set('xlwms.live_dispatch_enabled',true);
        config()->set('xlwms.live_order_id',$o->id);
        Http::fake(function($req){
            if(str_contains($req->url(),'outboundOrder/create'))throw new \RuntimeException('simulated timeout');
            if(str_contains($req->url(),'outboundOrder/pageList'))return Http::response([
                'code'=>200,'data'=>['records'=>[],'total'=>0],
            ]);
            if(str_contains($req->url(),'logistics/channel/list'))return Http::response(['code'=>200,'data'=>[
                ['channelCode'=>'No_Shipping_Service','whCodes'=>['OMS-US-SM']],
            ]]);
            if(str_contains($req->url(),'product/pagelist'))return Http::response(['code'=>200,'data'=>[
                'records'=>[['sku'=>'SAME-SKU','approveStatus'=>2]],
            ]]);
            return Http::response(['code'=>200,'data'=>[]]);
        });
        $s=app(SingleOrderDispatch::class);
        $p=$s->preview($o->id);
        $r=$s->dispatch($o->id,$p['payload_hash'],3);
        $this->assertSame('uncertain_manual_reconcile',$r['state']);
        $this->assertSame('uncertain_manual_reconcile',XlwmsDispatchAttempt::query()->sole()->state);
        // Faked HTTP exceptions are not recorded as completed sends; the durable
        // state nevertheless stays uncertain and no retry is attempted.
        Http::assertSentCount(5);
        $this->assertSame('pending',$o->shipment()->first()->shipment_status);
    }

    public function test_insufficient_local_warehouse_inventory_blocks_dispatch(): void
    {
        $o=$this->orderFixture();
        WarehouseInventory::query()->update(['quantity_on_hand'=>0]);
        $p=app(SingleOrderDispatch::class)->preview($o->id);
        $this->assertContains('insufficient_warehouse_stock:SAME-SKU',$p['blockers']);
        $this->assertFalse($p['ready_for_remote_check']);
    }

    public function test_missing_oms_binding_blocks_preflight(): void
    {
        $o=$this->orderFixture();
        WarehouseOpenApiConnection::query()->update(['oms_warehouse_code'=>null]);
        $p=app(SingleOrderDispatch::class)->preview($o->id);
        $this->assertContains('oms_warehouse_not_bound',$p['blockers']);
        $this->assertFalse($p['ready_for_remote_check']);
    }

    private function statusAttempt(Order $order, string $omsNo = 'OBS-SAFE-123'): XlwmsDispatchAttempt
    {
        return XlwmsDispatchAttempt::query()->create([
            'order_id'=>$order->id,
            'warehouse_id'=>$order->shipment->warehouse_id,
            'third_order_no'=>'BYL-ERP-'.$order->id,
            'request_fingerprint'=>str_repeat('a',64),
            'state'=>'oms_draft_created',
            'oms_order_no'=>$omsNo,
        ]);
    }

    public function test_readonly_status_refresh_of_shipped_oms_order_keeps_erp_pending(): void
    {
        $o=$this->orderFixture();
        $attempt=$this->statusAttempt($o);
        Http::preventStrayRequests();
        Http::fake(fn($request)=>Http::response(['code'=>200,'data'=>[
            'records'=>[[
                'outboundOrderNo'=>'OBS-SAFE-123',
                'thirdOrderNo'=>'BYL-ERP-'.$o->id,
                'whCode'=>'OMS-US-SM','status'=>3,
                'logisticsTrackNo'=>'TESTTRACK123',
            ]],
            'total'=>1,'page'=>1,'pageSize'=>10,'pages'=>1,
        ]]));
        $r=app(SingleOrderDispatch::class)->checkStatus($o->id);
        $this->assertTrue($r['read_only']);
        $this->assertTrue($r['fresh']);
        $this->assertSame('3',$r['oms_status_code']);
        $this->assertSame('已出库',$r['oms_status_label']);
        $this->assertSame('TESTTRACK123',$r['oms_tracking_no']);
        $this->assertNotNull($r['oms_status_checked_at']);
        $this->assertSame('pending_shipment',$o->fresh()->order_status->value);
        $this->assertSame('pending',$o->shipment()->first()->shipment_status);
        $this->assertSame('oms_draft_created',$attempt->fresh()->state);
        Http::assertSentCount(1);
        Http::assertSent(fn($r)=>str_contains($r->url(),'outboundOrder/pageList')
            && (is_object($r['data'])
                ? ($r['data']->outboundOrderNos==='OBS-SAFE-123' && $r['data']->pageSize===10)
                : ($r['data']['outboundOrderNos']==='OBS-SAFE-123' && $r['data']['pageSize']===10)));
        Http::assertNotSent(fn($r)=>str_contains($r->url(),'outboundOrder/create'));
    }

    public function test_cancelled_order_status_is_recorded_without_canceling_erp_or_woo(): void
    {
        $o=$this->orderFixture();
        $this->statusAttempt($o);
        Http::preventStrayRequests();
        Http::fake(fn()=>Http::response(['code'=>200,'data'=>[
            'records'=>[[
                'outboundOrderNo'=>'OBS-SAFE-123',
                'thirdOrderNo'=>'BYL-ERP-'.$o->id,
                'whCode'=>'OMS-US-SM','status'=>4,
            ]],'total'=>1,
        ]]));
        $r=app(SingleOrderDispatch::class)->checkStatus($o->id);
        $this->assertSame('4',$r['oms_status_code']);
        $this->assertSame('已取消',$r['oms_status_label']);
        $this->assertSame('pending_shipment',$o->fresh()->order_status->value);
    }

    public function test_unknown_vendor_status_stays_unknown_and_never_auto_completes(): void
    {
        $o=$this->orderFixture();
        $this->statusAttempt($o);
        Http::fake(fn()=>Http::response(['code'=>200,'data'=>[
            'records'=>[[
                'outboundOrderNo'=>'OBS-SAFE-123',
                'thirdOrderNo'=>'BYL-ERP-'.$o->id,
                'whCode'=>'OMS-US-SM','status'=>8,
            ]],'total'=>1,
        ]]));
        $r=app(SingleOrderDispatch::class)->checkStatus($o->id);
        $this->assertSame('OMS 状态 8（待核实）',$r['oms_status_label']);
        $this->assertSame('pending_shipment',$o->fresh()->order_status->value);
    }

    public function test_wrong_oms_warehouse_does_not_overwrite_last_confirmed_status(): void
    {
        $o=$this->orderFixture();
        $a=$this->statusAttempt($o);
        $a->forceFill(['oms_status_code'=>'3','oms_status_checked_at'=>now()->subDay()])->save();
        Http::preventStrayRequests();
        Http::fake(fn()=>Http::response(['code'=>200,'data'=>[
            'records'=>[[
                'outboundOrderNo'=>'OBS-SAFE-123','thirdOrderNo'=>'BYL-ERP-'.$o->id,
                'whCode'=>'OTHER-WAREHOUSE','status'=>4,
            ]],'total'=>1,
        ]]));
        $r=app(SingleOrderDispatch::class)->checkStatus($o->id);
        $this->assertFalse($r['fresh']);
        $this->assertSame('3',$r['oms_status_code']);
        $this->assertSame('OMS_STATUS_IDENTITY_MISMATCH',$r['oms_status_failure_code']);
        $this->assertSame('oms_draft_created',$a->fresh()->state);
    }

    public function test_manual_order_reference_must_match_if_oms_third_number_differs(): void
    {
        $o=$this->orderFixture();
        $a=$this->statusAttempt($o);
        Http::preventStrayRequests();
        Http::fake(fn()=>Http::response(['code'=>200,'data'=>[
            'records'=>[[
                'outboundOrderNo'=>'OBS-SAFE-123',
                'thirdOrderNo'=>'MANUAL-OTHER',
                'platformOrderNo'=>'UNKNOWN-OTHER',
                'referOrderNo'=>'UNKNOWN-OTHER',
                'whCode'=>'OMS-US-SM','status'=>3,
            ]],'total'=>1,
        ]]));
        $r=app(SingleOrderDispatch::class)->checkStatus($o->id);
        $this->assertFalse($r['fresh']);
        $this->assertNull($a->fresh()->oms_status_code);
        $this->assertSame('OMS_STATUS_IDENTITY_MISMATCH',$r['oms_status_failure_code']);
    }

    public function test_view_only_logistics_user_can_query_oms_status_without_dispatch_permission(): void
    {
        $o=$this->orderFixture();
        $this->statusAttempt($o);
        $viewer=\App\Models\User::factory()->create(['is_active'=>true]);
        $role=\App\Models\Role::query()->firstOrCreate(
            ['key'=>'xlwms_readonly_status_viewer'],['label'=>'XLWMS Viewer']
        );
        $viewPermission=\App\Models\Permission::query()->firstOrCreate(
            ['key'=>'logistics.view'],['label'=>'View Logistics']
        );
        $role->permissions()->syncWithoutDetaching([$viewPermission->id]);
        $viewer->roles()->attach($role);
        Http::preventStrayRequests();
        Http::fake(fn()=>Http::response(['code'=>200,'data'=>[
            'records'=>[[
                'outboundOrderNo'=>'OBS-SAFE-123',
                'thirdOrderNo'=>'BYL-ERP-'.$o->id,
                'whCode'=>'OMS-US-SM','status'=>4,
            ]],'total'=>1,
        ]]));

        $this->actingAs($viewer)->postJson(
            '/logistics/oversea/us-3sm/orders/'.$o->id.'/xlwms/status'
        )->assertOk()->assertJsonPath('read_only',true)
            ->assertJsonPath('oms_status_label','已取消');
        $this->assertSame('pending_shipment',$o->fresh()->order_status->value);
        Http::assertNotSent(fn($r)=>str_contains($r->url(),'outboundOrder/create'));
    }

    public function test_user_without_logistics_view_cannot_query_oms_status(): void
    {
        $o=$this->orderFixture();
        $this->statusAttempt($o);
        $viewer=\App\Models\User::factory()->create(['is_active'=>true]);
        Http::preventStrayRequests();
        Http::fake();

        $this->actingAs($viewer)->postJson(
            '/logistics/oversea/us-3sm/orders/'.$o->id.'/xlwms/status'
        )->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_status_request_without_prior_dispatch_is_rejected_without_network(): void
    {
        $o=$this->orderFixture();
        Http::preventStrayRequests();
        Http::fake();
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(SingleOrderDispatch::class)->checkStatus($o->id);
    }

    public function test_empty_exact_status_result_preserves_previous_state_and_does_not_retry(): void
    {
        $o=$this->orderFixture();
        $a=$this->statusAttempt($o);
        Http::preventStrayRequests();
        Http::fake(fn()=>Http::response(['code'=>200,'data'=>[
            'records'=>[],'total'=>0,
        ]]));
        $r=app(SingleOrderDispatch::class)->checkStatus($o->id);
        $this->assertFalse($r['fresh']);
        $this->assertSame('OMS_STATUS_NOT_FOUND',$r['oms_status_failure_code']);
        $this->assertSame('oms_draft_created',$a->fresh()->state);
        Http::assertSentCount(1);
        Http::assertNotSent(fn($r)=>str_contains($r->url(),'outboundOrder/create'));
    }

    public function test_warehouse_batch_is_off_by_default_persists_blocked_outcome_and_does_not_duplicate(): void
    {
        $o=$this->orderFixture();
        config()->set('xlwms.warehouse_batch_dispatch_enabled',false);
        config()->set('xlwms.live_dispatch_enabled',false);
        Http::preventStrayRequests();
        Http::fake(fn($request)=>str_contains($request->url(),'outboundOrder/detail')
            ? Http::response(['code'=>200,'data'=>[]])
            : Http::response(['code'=>200,'data'=>['records'=>[],'total'=>0]]));
        $service=app(WarehouseBatchDispatch::class);
        $first=$service->run(7);
        $second=$service->run(7);
        $this->assertSame([], $first['results']);
        $this->assertSame([], $second['results']);
        $this->assertDatabaseCount('xlwms_dispatch_attempts',0);
        Http::assertNothingSent();
        Http::assertNotSent(fn($request)=>str_contains($request->url(),'outboundOrder/create'));
    }

    public function test_batch_missing_oms_native_order_number_never_creates_or_retries(): void
    {
        $o = $this->orderFixture();
        config()->set('xlwms.warehouse_batch_dispatch_enabled', true);
        config()->set('xlwms.live_dispatch_enabled', true);
        config()->set('xlwms.live_order_id', $o->id);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($o) {
            if (str_contains($request->url(), 'outboundOrder/detail')) {
                return Http::response(['code'=>200, 'data'=>[[
                    'thirdOrderNo'=>'BYL-ERP-'.$o->id,
                    'platformOrderNo'=>$o->external_order_number,
                    'whCode'=>'OMS-US-SM', 'productList'=>[['sku'=>'SAME-SKU','quantity'=>1]],
                ]]]);
            }
            return Http::response(['code'=>200, 'data'=>['records'=>[], 'total'=>0]]);
        });
        $first = app(WarehouseBatchDispatch::class)->run(7);
        $second = app(WarehouseBatchDispatch::class)->run(7);
        $this->assertSame('missing_order_id', $first['results'][0]['code']);
        $this->assertSame('missing_order_id', $second['results'][0]['code']);
        $this->assertSame('batch_identity_blocked', XlwmsDispatchAttempt::query()->where('order_id',$o->id)->firstOrFail()->state);
        Http::assertNotSent(fn($request)=>str_contains($request->url(), 'outboundOrder/create'));
    }

    public function test_batch_safe_precreate_block_is_reclaimable_without_duplicate_attempt(): void
    {
        $o = $this->orderFixture();
        config()->set('xlwms.warehouse_batch_dispatch_enabled', false);
        config()->set('xlwms.live_dispatch_enabled', false);
        Http::preventStrayRequests();
        Http::fake(fn($request) => str_contains($request->url(), 'outboundOrder/detail')
            ? Http::response(['code'=>200,'data'=>[]])
            : Http::response(['code'=>200,'data'=>['records'=>[],'total'=>0]]));
        $first = app(WarehouseBatchDispatch::class)->run(7);
        $this->assertSame([], $first['results']);
        $second = app(WarehouseBatchDispatch::class)->run(8);
        $this->assertSame([], $second['results']);
        $this->assertSame('create_disabled', $first['blocked_reason']);
        $this->assertDatabaseCount('xlwms_dispatch_attempts', 0);
        Http::assertNothingSent();
        Http::assertNotSent(fn($request)=>str_contains($request->url(), 'outboundOrder/create'));
    }

    public function test_batch_does_not_process_an_existing_processing_claim_again(): void
    {
        $o=$this->orderFixture();
        config()->set('xlwms.warehouse_batch_dispatch_enabled', true);
        config()->set('xlwms.live_dispatch_enabled', true);
        config()->set('xlwms.live_order_id', $o->id);
        XlwmsDispatchAttempt::query()->create([
            'order_id'=>$o->id,'warehouse_id'=>$o->shipment->warehouse_id,'third_order_no'=>'BYL-ERP-'.$o->id,
            'state'=>'batch_processing','request_fingerprint'=>str_repeat('a',64),'requested_by'=>7,
        ]);
        Http::preventStrayRequests();
        Http::fake();
        $result=app(WarehouseBatchDispatch::class)->run(8);
        $this->assertSame('in_progress',$result['results'][0]['code']);
        Http::assertNothingSent();
    }

    public function test_existing_oms_shipped_tracking_is_read_back_when_creation_is_off(): void
    {
        $o=$this->orderFixture();
        config()->set('xlwms.warehouse_batch_dispatch_enabled', true);
        config()->set('xlwms.live_dispatch_enabled', true);
        config()->set('xlwms.live_order_id', $o->id);
        config()->set('xlwms.warehouse_batch_dispatch_enabled',true);
        config()->set('xlwms.live_dispatch_enabled',true);
        Http::preventStrayRequests();
        Http::fake(function($request) use ($o) {
            if (str_contains($request->url(),'outboundOrder/detail')) {
                return Http::response(['code'=>200,'data'=>[[
                    'thirdOrderNo'=>'BYL-ERP-'.$o->id,'platformOrderNo'=>$o->external_order_number,
                    'whCode'=>'OMS-US-SM','productList'=>[['sku'=>'SAME-SKU','quantity'=>1]],
                    'outboundOrderNo'=>'OMS-SHIPPED-1',
                ]]]);
            }
            if (str_contains($request->url(),'outboundOrder/pageList')) {
                return Http::response(['code'=>200,'data'=>['records'=>[ [
                    'outboundOrderNo'=>'OMS-SHIPPED-1','whCode'=>'OMS-US-SM',
                    'thirdOrderNo'=>'BYL-ERP-'.$o->id,'platformOrderNo'=>$o->external_order_number,
                    'status'=>'3','logisticsTrackNo'=>'TRACK-123',
                ] ],'total'=>1]]);
            }
            return Http::response(['code'=>500]);
        });
        $result=app(WarehouseBatchDispatch::class)->run(7);
        $this->assertSame('carrier_verification_required',$result['results'][0]['code']);
        $this->assertNull($o->fresh()->shipment->tracking_number);
        $this->assertSame('pending',$o->fresh()->shipment->shipment_status);
        $this->assertSame('TRACK-123', XlwmsDispatchAttempt::query()->where('order_id', $o->id)->firstOrFail()->oms_tracking_no);
        Http::assertNotSent(fn($request)=>str_contains($request->url(),'outboundOrder/create'));
    }

    public function test_uncertain_create_attempt_is_never_retried_when_exact_lookup_is_empty(): void
    {
        $o=$this->orderFixture();
        config()->set('xlwms.warehouse_batch_dispatch_enabled', true);
        config()->set('xlwms.live_dispatch_enabled', true);
        config()->set('xlwms.live_order_id', $o->id);
        XlwmsDispatchAttempt::query()->create([
            'order_id'=>$o->id,'warehouse_id'=>$o->shipment->warehouse_id,'third_order_no'=>'BYL-ERP-'.$o->id,
            'state'=>'batch_uncertain','request_fingerprint'=>str_repeat('b',64),'requested_by'=>7,
        ]);
        Http::preventStrayRequests();
        Http::fake(fn($request)=>str_contains($request->url(),'outboundOrder/detail')
            ? Http::response(['code'=>200,'data'=>[]])
            : Http::response(['code'=>200,'data'=>['records'=>[],'total'=>0]]));
        $result=app(WarehouseBatchDispatch::class)->run(7);
        $this->assertSame('uncertain',$result['results'][0]['code']);
        Http::assertNotSent(fn($request)=>str_contains($request->url(),'outboundOrder/create'));
    }

    public function test_batch_canceled_and_unknown_oms_statuses_never_mark_erp_shipped(): void
    {
        foreach ([4 => 'canceled'] as $status => $expected) {
            $o=$this->orderFixture();
        config()->set('xlwms.warehouse_batch_dispatch_enabled', true);
        config()->set('xlwms.live_dispatch_enabled', true);
        config()->set('xlwms.live_order_id', $o->id);
            config()->set('xlwms.warehouse_batch_dispatch_enabled',true);
            config()->set('xlwms.live_dispatch_enabled',true);
            Http::preventStrayRequests();
            Http::fake(function($request) use ($o, $status) {
                if (str_contains($request->url(),'outboundOrder/detail')) return Http::response(['code'=>200,'data'=>[[
                    'thirdOrderNo'=>'BYL-ERP-'.$o->id,'platformOrderNo'=>$o->external_order_number,
                    'whCode'=>'OMS-US-SM','productList'=>[['sku'=>'SAME-SKU','quantity'=>1]], 'outboundOrderNo'=>'OMS-STATE-'.$o->id,
                ]]]);
                return Http::response(['code'=>200,'data'=>['records'=>[[
                    'outboundOrderNo'=>'OMS-STATE-'.$o->id,'whCode'=>'OMS-US-SM',
                    'thirdOrderNo'=>'BYL-ERP-'.$o->id,'platformOrderNo'=>$o->external_order_number,
                    'status'=>(string) $status,'logisticsTrackNo'=>null,
                ]],'total'=>1]]);
            });
            $result=app(WarehouseBatchDispatch::class)->run(7);
            $this->assertSame($expected,$result['results'][0]['code']);
            $this->assertSame('pending',$o->fresh()->shipment->shipment_status);
            Http::assertNotSent(fn($request)=>str_contains($request->url(),'outboundOrder/create'));
        }
    }

    public function test_auto_lowest_price_channel_is_exact_and_does_not_rewrite_erp_carrier(): void
    {
        $o = $this->orderFixture();
        $o->shipment()->update(['carrier'=>'USPS', 'service'=>'Priority Mail']);
        $p = app(SingleOrderDispatch::class)->preview($o->id);
        $this->assertSame('No_Shipping_Service', $p['payload']['logisticsChannel']);
        $this->assertSame('USPS', $o->fresh()->shipment->carrier);
        $this->assertSame('Priority Mail', $o->fresh()->shipment->service);
        $this->assertFalse((bool)config('xlwms.live_dispatch_enabled'));
        $this->assertFalse((bool)config('xlwms.warehouse_batch_dispatch_enabled'));
    }

    public function test_approved_single_canary_batch_creates_only_exact_order_draft_without_label(): void
    {
        $o=$this->orderFixture();
        config()->set('xlwms.warehouse_batch_dispatch_enabled',true);
        config()->set('xlwms.live_dispatch_enabled',true);
        config()->set('xlwms.live_order_id',$o->id);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($o) {
            $url=$request->url();
            if(str_contains($url,'outboundOrder/detail')) return Http::response(['code'=>200,'data'=>[]]);
            if(str_contains($url,'outboundOrder/pageList')) return Http::response(['code'=>200,'data'=>['records'=>[],'total'=>0]]);
            if(str_contains($url,'logistics/channel/list')) return Http::response(['code'=>200,'data'=>[['channelCode'=>'No_Shipping_Service','whCodes'=>[]]]]);
            if(str_contains($url,'product/pagelist')) return Http::response(['code'=>200,'data'=>['records'=>[['sku'=>'SAME-SKU','approveStatus'=>2]]]]);
            if(str_contains($url,'outboundOrder/create')) return Http::response(['code'=>200,'data'=>[['thirdOrderNo'=>'BYL-ERP-'.$o->id,'success'=>true,'orderNo'=>'OMS-AUTO-LOW-01']]]);
            return Http::response(['code'=>500]);
        });
        $r=app(WarehouseBatchDispatch::class)->run(7);
        $this->assertSame('created', $r['results'][0]['code']);
        $this->assertSame('batch_draft_created', XlwmsDispatchAttempt::query()->where('order_id',$o->id)->firstOrFail()->state);
        Http::assertSent(fn($req)=>str_contains($req->url(),'outboundOrder/create')
            && (is_object($req['data'][0]) ? $req['data'][0]->logisticsChannel : $req['data'][0]['logisticsChannel']) === 'No_Shipping_Service');
        $this->assertSame('pending', $o->fresh()->shipment->shipment_status);
        $this->assertSame('USPS', $o->fresh()->shipment->carrier);
        $this->assertNull($o->fresh()->shipment->tracking_number);
        Http::assertSentCount(6);
    }

    public function test_batch_cannot_create_for_any_order_without_exact_canary_allowlist(): void
    {
        $o=$this->orderFixture();
        config()->set('xlwms.warehouse_batch_dispatch_enabled',true);
        config()->set('xlwms.live_dispatch_enabled',true);
        config()->set('xlwms.live_order_id',0);
        Http::preventStrayRequests();
        Http::fake(fn($request)=>str_contains($request->url(),'outboundOrder/detail')
            ? Http::response(['code'=>200,'data'=>[]])
            : Http::response(['code'=>200,'data'=>['records'=>[],'total'=>0]]));
        $first=app(WarehouseBatchDispatch::class)->run(7);
        $this->assertSame([], $first['results']);
        $this->assertSame('create_disabled', $first['blocked_reason']);
        $this->assertDatabaseCount('xlwms_dispatch_attempts', 0);
        $this->assertSame('pending',$o->fresh()->shipment->shipment_status);
        Http::assertNotSent(fn($request)=>str_contains($request->url(),'outboundOrder/create'));
    }

    public function test_canary_approval_for_other_order_never_creates_current_order(): void
    {
        $o=$this->orderFixture();
        config()->set('xlwms.warehouse_batch_dispatch_enabled',true);
        config()->set('xlwms.live_dispatch_enabled',true);
        config()->set('xlwms.live_order_id',$o->id+999);
        Http::preventStrayRequests();
        Http::fake(fn($request)=>str_contains($request->url(),'outboundOrder/detail')
            ? Http::response(['code'=>200,'data'=>[]])
            : Http::response(['code'=>200,'data'=>['records'=>[],'total'=>0]]));
        $r=app(WarehouseBatchDispatch::class)->run(7);
        $this->assertSame([], $r['results']); // Only the approved ID can enter the bounded Canary query.
        Http::assertNotSent(fn($request)=>str_contains($request->url(),'outboundOrder/create'));
    }

    public function test_batch_with_creation_flags_on_still_blocks_without_fresh_applied_v2_stock(): void
    {
        $o = $this->orderFixture();
        // This fixture normally creates a fresh applied snapshot; make it stale
        // to prove a create is forbidden even when both dispatch flags are ON.
        XlwmsInventorySnapshot::query()->where('warehouse_id', $o->shipment->warehouse_id)
            ->update(['fetched_at' => now()->subMinutes(10)]);
        config()->set('xlwms.warehouse_batch_dispatch_enabled', true);
        config()->set('xlwms.live_dispatch_enabled', true);
        config()->set('xlwms.live_order_id', $o->id);
        Http::preventStrayRequests();
        Http::fake(fn ($request) => str_contains($request->url(), 'outboundOrder/detail')
            ? Http::response(['code' => 200, 'data' => []])
            : Http::response(['code' => 200, 'data' => ['records' => [], 'total' => 0]]));
        $result = app(WarehouseBatchDispatch::class)->run(7);
        $this->assertSame('preflight_blocked', $result['results'][0]['code']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'outboundOrder/create'));
    }

    public function test_batch_existing_order_wrong_sku_quantity_blocks_tracking_write(): void
    {
        foreach ([2, null] as $providerQuantity) {
            $o = $this->orderFixture();
        config()->set('xlwms.warehouse_batch_dispatch_enabled', true);
        config()->set('xlwms.live_dispatch_enabled', true);
        config()->set('xlwms.live_order_id', $o->id);
            config()->set('xlwms.warehouse_batch_dispatch_enabled', true);
            config()->set('xlwms.live_dispatch_enabled', true);
            Http::preventStrayRequests();
            Http::fake(function ($request) use ($o, $providerQuantity) {
                if (str_contains($request->url(), 'outboundOrder/detail')) {
                    $product = ['sku'=>'SAME-SKU'];
                    if ($providerQuantity !== null) $product['quantity'] = $providerQuantity;
                    return Http::response(['code'=>200, 'data'=>[[
                        'thirdOrderNo'=>'BYL-ERP-'.$o->id,
                        'platformOrderNo'=>$o->external_order_number,
                        'whCode'=>'OMS-US-SM', 'productList'=>[$product],
                        'outboundOrderNo'=>'OMS-QUANTITY-MISMATCH-'.$o->id,
                    ]]]);
                }
                return Http::response(['code'=>200, 'data'=>['records'=>[], 'total'=>0]]);
            });
            $result = app(WarehouseBatchDispatch::class)->run(7);
            $this->assertContains($result['results'][0]['code'], ['OMS_STATUS_IDENTITY_MISMATCH', 'error']);
            $this->assertSame('pending', $o->fresh()->shipment->shipment_status);
            $this->assertNull($o->fresh()->shipment->tracking_number);
            Http::assertNotSent(fn($r)=>str_contains($r->url(), 'outboundOrder/create'));
        }
    }

    public function test_uncertain_batch_attempt_preserved_when_followup_oms_lookup_fails(): void
    {
        $o = $this->orderFixture();
        XlwmsDispatchAttempt::query()->create([
            'order_id'=>$o->id, 'warehouse_id'=>$o->shipment->warehouse_id,
            'third_order_no'=>'BYL-ERP-'.$o->id,
            'state'=>'batch_uncertain', 'request_fingerprint'=>str_repeat('c',64), 'requested_by'=>7,
        ]);
        config()->set('xlwms.warehouse_batch_dispatch_enabled', true);
        config()->set('xlwms.live_dispatch_enabled', true);
        config()->set('xlwms.live_order_id', $o->id);
        Http::preventStrayRequests();
        Http::fake(fn()=>Http::response(['code'=>500]));
        $r = app(WarehouseBatchDispatch::class)->run(7);
        $this->assertSame('uncertain', $r['results'][0]['code']);
        $this->assertSame('batch_uncertain', XlwmsDispatchAttempt::query()->where('order_id',$o->id)->firstOrFail()->state);
        Http::assertNotSent(fn($x)=>str_contains($x->url(), 'outboundOrder/create'));
    }

    public function test_previously_blocked_attempt_cannot_create_after_flags_are_enabled(): void
    {
        $o = $this->orderFixture();
        XlwmsDispatchAttempt::query()->create([
            'order_id'=>$o->id, 'warehouse_id'=>$o->shipment->warehouse_id,
            'third_order_no'=>'BYL-ERP-'.$o->id,
            'state'=>'batch_blocked', 'request_fingerprint'=>str_repeat('d',64), 'requested_by'=>7,
        ]);
        config()->set('xlwms.warehouse_batch_dispatch_enabled', true);
        config()->set('xlwms.live_dispatch_enabled', true);
        config()->set('xlwms.live_order_id', $o->id);
        Http::preventStrayRequests();
        Http::fake(fn($request)=>str_contains($request->url(), 'outboundOrder/detail')
            ? Http::response(['code'=>200, 'data'=>[]])
            : Http::response(['code'=>200, 'data'=>['records'=>[], 'total'=>0]]));
        $r = app(WarehouseBatchDispatch::class)->run(7);
        $this->assertSame('blocked', $r['results'][0]['code']);
        Http::assertNotSent(fn($x)=>str_contains($x->url(), 'outboundOrder/create'));
    }

    public function test_readonly_oms_verifier_returns_only_sanitized_status_without_writes(): void
    {
        $o = $this->orderFixture();
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($o) {
            if (str_contains($request->url(), 'outboundOrder/detail')) {
                return Http::response(['code'=>200, 'data'=>[[
                    'thirdOrderNo'=>'BYL-ERP-'.$o->id,
                    'platformOrderNo'=>$o->external_order_number,
                    'whCode'=>'OMS-US-SM',
                    'productList'=>[['sku'=>'SAME-SKU', 'quantity'=>1]],
                    'outboundOrderNo'=>'OMS-READ-535',
                ]]]);
            }
            if (str_contains($request->url(), 'outboundOrder/pageList')) {
                return Http::response(['code'=>200, 'data'=>['records'=>[[
                    'outboundOrderNo'=>'OMS-READ-535', 'thirdOrderNo'=>'BYL-ERP-'.$o->id,
                    'platformOrderNo'=>$o->external_order_number, 'whCode'=>'OMS-US-SM',
                    'status'=>3, 'logisticsTrackNo'=>'TRACK-PRIVATE-123',
                ]], 'total'=>1]]);
            }
            return Http::response(['code'=>500]);
        });
        $result = \Illuminate\Support\Facades\Artisan::call('xlwms:verify-outbound-readonly', ['order'=>$o->erp_order_id]);
        $output = \Illuminate\Support\Facades\Artisan::output();
        $this->assertSame(0, $result);
        $this->assertStringContainsString('RESULT=SHIPPED_TRACKING_PRESENT; OMS_STATUS_CODE=3; WRITE_PERFORMED=NO', $output);
        $this->assertStringNotContainsString('TRACK-PRIVATE-123', $output);
        $this->assertStringNotContainsString('Test Buyer', $output);
        $this->assertSame('pending', $o->fresh()->shipment->shipment_status);
        $this->assertNull($o->fresh()->shipment->tracking_number);
        $this->assertDatabaseCount('xlwms_dispatch_attempts', 0);
        Http::assertNotSent(fn($request)=>str_contains($request->url(), 'outboundOrder/create'));
    }

    public function test_readonly_oms_verifier_fails_closed_when_quantity_differs(): void
    {
        $o = $this->orderFixture();
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($o) {
            return Http::response(['code'=>200, 'data'=>[[
                'thirdOrderNo'=>'BYL-ERP-'.$o->id,
                'platformOrderNo'=>$o->external_order_number, 'whCode'=>'OMS-US-SM',
                'productList'=>[['sku'=>'SAME-SKU', 'quantity'=>5]],
                'outboundOrderNo'=>'OMS-BAD-535',
            ]]]);
        });
        $result = \Illuminate\Support\Facades\Artisan::call('xlwms:verify-outbound-readonly', ['order'=>$o->erp_order_id]);
        $this->assertSame(1, $result);
        $this->assertStringContainsString('BLOCKED=OMS_STATUS_IDENTITY_MISMATCH; WRITE_PERFORMED=NO', \Illuminate\Support\Facades\Artisan::output());
        $this->assertSame('pending', $o->fresh()->shipment->shipment_status);
        Http::assertNotSent(fn($request)=>str_contains($request->url(), 'outboundOrder/create'));
    }

    public function test_readonly_oms_verifier_denies_absent_credentials_without_network(): void
    {
        $o = $this->orderFixture();
        WarehouseOpenApiConnection::query()->where('warehouse_id', $o->shipment->warehouse_id)->delete();
        Http::preventStrayRequests();
        Http::fake();
        $result = \Illuminate\Support\Facades\Artisan::call('xlwms:verify-outbound-readonly', ['order'=>$o->erp_order_id]);
        $this->assertSame(1, $result);
        $this->assertStringContainsString('BLOCKED=US_SM_OMS_CONNECTION_MISSING', \Illuminate\Support\Facades\Artisan::output());
        Http::assertNothingSent();
        $this->assertDatabaseCount('xlwms_dispatch_attempts', 0);
    }

    public function test_warehouse_batch_route_requires_logistics_operation_permission(): void
    {
        $this->postJson('/logistics/oversea/us-3sm/xlwms/sync')->assertRedirect();
    }

    public function test_warehouse_batch_never_selects_a_different_warehouse(): void
    {
        $o=$this->orderFixture();
        $other=Warehouse::query()->firstOrCreate(['code'=>'US-NY'], ['slug'=>'us-ny','name'=>'Other','type'=>'warehouse','is_active'=>true]);
        $o->shipment()->update(['warehouse_id'=>$other->id]);
        $o->shipment()->first()->overseaReview()->update(['reviewed_warehouse_id'=>$other->id]);
        config()->set('xlwms.warehouse_batch_dispatch_enabled',true);
        config()->set('xlwms.live_dispatch_enabled',true);
        Http::preventStrayRequests();
        Http::fake();
        $result=app(WarehouseBatchDispatch::class)->run(7);
        $this->assertSame([], $result['results']);
        $this->assertDatabaseCount('xlwms_dispatch_attempts',0);
        Http::assertNothingSent();
    }

    public function test_m1_business_projection_does_not_claim_blocked_order_was_dispatched(): void
    {
        $o = $this->orderFixture();
        $a = XlwmsDispatchAttempt::query()->create([
            'order_id' => $o->id, 'warehouse_id' => $o->shipment->warehouse_id,
            'third_order_no' => 'BYL-ERP-'.$o->id,
            'state' => 'batch_blocked', 'failure_code' => 'create_disabled',
            'request_fingerprint' => str_repeat('f', 64),
        ]);
        $projection = \App\Modules\Logistics\Application\WarehouseDispatchStatus::from('pending', $a);
        $this->assertSame('pending_dispatch', $projection['status']);
        $this->assertNull($projection['oms_order_no']);
        $this->assertTrue($projection['can_retry_local_block']);
        $this->assertSame('未下发 · 建单权限关闭', $projection['detail']);
        $pre = app(SingleOrderDispatch::class)->preview($o->id);
        $this->assertSame([], $pre['blockers']);
        $this->assertTrue($pre['ready_for_remote_check']);
        $this->assertTrue($pre['retryable_local_block']);
        Http::assertNothingSent();
    }

    public function test_m1_audited_oms_dispatch_status_is_idempotent_and_code_five_is_not_shipped(): void
    {
        $o = $this->orderFixture();
        $a = XlwmsDispatchAttempt::query()->create([
            'order_id'=>$o->id, 'warehouse_id'=>$o->shipment->warehouse_id,
            'third_order_no'=>'BYL-ERP-'.$o->id, 'state'=>'batch_created',
            'oms_order_no'=>'OMS-M1-0001','request_fingerprint'=>str_repeat('1',64),
            'oms_status_code'=>'5', 'oms_status_checked_at'=>now(),
        ]);
        $status = \App\Modules\Logistics\Application\WarehouseDispatchStatus::from('pending', $a);
        $this->assertSame('exception', $status['status']);
        $this->assertSame('OMS 异常 · 待处理', $status['label']);
        $this->assertSame('仓库未正常接单，请核实 OMS 异常原因', $status['detail']);
        $recorder=app(\App\Modules\Logistics\Application\WarehouseDispatchRecorder::class);
        $recorder->recordConfirmed($o,$a);
        $recorder->recordConfirmed($o,$a);
        $this->assertDatabaseCount('warehouse_dispatch_states',1);
        $this->assertDatabaseCount('warehouse_dispatch_events',1);
        $this->assertDatabaseHas('warehouse_dispatch_states',[
            'order_id'=>$o->id,'status'=>'exception','provider_order_no'=>'OMS-M1-0001',
        ]);
        $a->forceFill(['oms_status_code'=>'3'])->save();
        $recorder->recordConfirmed($o,$a);
        $this->assertDatabaseHas('warehouse_dispatch_states',[
            'order_id'=>$o->id,'status'=>'warehouse_outbound_pending_sync',
        ]);
        $this->assertDatabaseCount('warehouse_dispatch_events',2);
        $this->assertSame('pending_shipment',$o->fresh()->order_status->value);
        $this->assertSame('pending',$o->fresh()->shipment->shipment_status);
        Http::assertNothingSent();
    }

    public function test_draft_create_without_live_status_is_unverified_not_warehouse_received(): void
    {
        $o=$this->orderFixture();
        $a=XlwmsDispatchAttempt::query()->create([
            'order_id'=>$o->id,'warehouse_id'=>$o->shipment->warehouse_id,
            'third_order_no'=>'BYL-ERP-'.$o->id,
            'state'=>'oms_draft_created','oms_order_no'=>'OMS-DRAFT-NOSTATUS',
            'request_fingerprint'=>str_repeat('d',64),
        ]);
        $v=\App\Modules\Logistics\Application\WarehouseDispatchStatus::from('pending',$a);
        $this->assertSame('oms_unverified',$v['status']);
        $this->assertSame('OMS 已建单 · 接单待核实',$v['label']);
        $this->assertSame('pending',$o->fresh()->shipment->shipment_status);
        Http::fake();Http::assertNothingSent();
    }

    public function test_issue544_reship_preview_is_readonly_and_preserves_original_shipped_order(): void
    {
        $o=$this->orderFixture();
        $o->update(['order_status'=>'shipped']);
        $o->shipment()->update(['shipment_status'=>'shipped','tracking_number'=>'TRACK-ORIGINAL']);
        $o=$o->fresh();
        $originalTracking=$o->shipment->tracking_number;
        Http::preventStrayRequests();
        Http::fake();
        $p=app(\App\Modules\Logistics\Application\Xlwms\ReshipmentPreview::class)->forOrder($o->id);
        $this->assertSame([], $p['blockers']);
        $this->assertTrue($p['ready_for_review']);
        $this->assertFalse($p['can_create_oms']);
        $this->assertFalse($p['memo_contract_verified']);
        $this->assertSame('No_Shipping_Service',$p['channel']);
        $this->assertSame('重发订单',$p['required_oms_memo']);
        $this->assertSame('仓库订单出错',$p['reason_default']);
        $this->assertSame('SAME-SKU',$p['items'][0]['sku']);
        $this->assertSame(1,$p['items'][0]['quantity']);
        $this->assertSame('shipped',$o->fresh()->order_status->value);
        $this->assertSame($originalTracking,$o->fresh()->shipment->tracking_number);
        $this->assertDatabaseCount('xlwms_reshipments',0);
        Http::assertNothingSent();
    }

    public function test_issue544_reship_preflight_requires_logistics_operator_permission(): void
    {
        $o=$this->orderFixture();
        $o->update(['order_status'=>'shipped']);
        $o->shipment()->update(['shipment_status'=>'shipped','tracking_number'=>'ORIGINAL-TRACK']);
        $viewer=\App\Models\User::factory()->create(['is_active'=>true]);
        Http::preventStrayRequests();Http::fake();
        $this->actingAs($viewer)->getJson('/logistics/oversea/us-3sm/orders/'.$o->id.'/xlwms/reship-preflight')
            ->assertForbidden();
        $this->assertDatabaseCount('xlwms_reshipments',0);
        Http::assertNothingSent();
    }

    public function test_issue544_reship_preview_blocks_unshipped_and_existing_reshipment(): void
    {
        $o=$this->orderFixture();
        Http::fake();
        $service=app(\App\Modules\Logistics\Application\Xlwms\ReshipmentPreview::class);
        $pending=$service->forOrder($o->id);
        $this->assertContains('source_order_not_shipped',$pending['blockers']);
        $this->assertFalse($pending['ready_for_review']);
        $o->update(['order_status'=>'shipped']);
        $o->shipment()->update(['shipment_status'=>'shipped','tracking_number'=>'ORIGINAL-TRACK']);
        $ship=$o->fresh()->shipment;
        \App\Modules\Logistics\Infrastructure\Xlwms\XlwmsReshipment::query()->create([
            'original_order_id'=>$o->id,
            'original_shipment_id'=>$ship->id,
            'warehouse_id'=>$ship->warehouse_id,
            'request_id'=>'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            'third_order_no'=>'BYL-RS-TEST-1',
            'status'=>'create_uncertain',
            'reason'=>'仓库订单出错',
            'memo'=>'重发订单',
            'payload_fingerprint'=>str_repeat('f',64),
            'sku_quantity_snapshot'=>[['sku'=>'SAME-SKU','quantity'=>1]],
            'requested_by'=>1,
        ]);
        $existing=$service->forOrder($o->id);
        $this->assertContains('existing_reshipment_requires_review',$existing['blockers']);
        $this->assertSame(1,$existing['existing_reshipment_count']);
        $this->assertFalse($existing['ready_for_review']);
        $this->assertDatabaseCount('xlwms_reshipments',1);
        Http::assertNothingSent();
    }

    public function test_m1_oms_draft_code_zero_keeps_warehouse_not_dispatched(): void
    {
        $o = $this->orderFixture();
        $a = XlwmsDispatchAttempt::query()->create([
            'order_id'=>$o->id,'warehouse_id'=>$o->shipment->warehouse_id,
            'third_order_no'=>'BYL-ERP-'.$o->id,'state'=>'batch_existing',
            'oms_order_no'=>'OMS-DRAFT-0001','request_fingerprint'=>str_repeat('f',64),
            'oms_status_code'=>'0','oms_status_checked_at'=>now(),
        ]);
        $projection=\App\Modules\Logistics\Application\WarehouseDispatchStatus::from('pending',$a);
        $this->assertSame('oms_draft',$projection['status']);
        $this->assertSame('OMS 草稿 · 未提交仓库',$projection['label']);
        $this->assertSame('无需面单；仓库接单状态仍需核对',$projection['detail']);
        app(\App\Modules\Logistics\Application\WarehouseDispatchRecorder::class)->recordConfirmed($o,$a);
        $this->assertDatabaseHas('warehouse_dispatch_states',['order_id'=>$o->id,'status'=>'oms_draft']);
        $this->assertSame('pending',$o->fresh()->shipment->shipment_status);
        Http::assertNothingSent();
    }

    public function test_m1_status_refresh_queries_one_real_oms_order_without_creating_or_shipping(): void
    {
        $o=$this->orderFixture();
        XlwmsDispatchAttempt::query()->create([
            'order_id'=>$o->id,'warehouse_id'=>$o->shipment->warehouse_id,
            'third_order_no'=>'BYL-ERP-'.$o->id,'state'=>'batch_created',
            'oms_order_no'=>'OMS-M1-0002','request_fingerprint'=>str_repeat('2',64),
        ]);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($o) {
            return Http::response(['code'=>200,'data'=>['total'=>1,'records'=>[[
                'outboundOrderNo'=>'OMS-M1-0002','whCode'=>'OMS-US-SM',
                'thirdOrderNo'=>'BYL-ERP-'.$o->id,'platformOrderNo'=>$o->external_order_number,
                'status'=>'5','logisticsTrackNo'=>'',
            ]]]]);
        });
        $result=app(\App\Modules\Logistics\Application\Xlwms\WarehouseStatusRefresh::class)->run();
        $this->assertSame(1,$result['refreshed']);
        $this->assertSame(0,$result['failed']);
        $this->assertSame('5',XlwmsDispatchAttempt::query()->where('order_id',$o->id)->value('oms_status_code'));
        $this->assertSame('pending',$o->fresh()->shipment->shipment_status);
        $this->assertNull($o->fresh()->shipment->tracking_number);
        Http::assertSentCount(1);
        Http::assertNotSent(fn($r)=>str_contains($r->url(),'outboundOrder/create'));
    }
}
