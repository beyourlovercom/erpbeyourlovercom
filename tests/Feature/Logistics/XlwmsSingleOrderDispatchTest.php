<?php

namespace Tests\Feature\Logistics;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Logistics\Application\Xlwms\SingleOrderDispatch;
use App\Modules\Logistics\Infrastructure\Models\Carrier;
use App\Modules\Logistics\Infrastructure\Models\CarrierService;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class XlwmsSingleOrderDispatchTest extends TestCase
{
    use RefreshDatabase;

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
        $o->items()->create(['product_id'=>$product->id,'product_summary'=>'Test','sku'=>'SAME-SKU','quantity'=>1,'unit_price'=>10,'line_revenue'=>10]);
        $shipment=$o->shipment()->create([
            'shipment_status'=>'pending','warehouse_id'=>$w->id,
            'carrier'=>'USPS','service'=>'OMS-STANDARD',
            'carrier_service_id'=>$service->id,'carrier_selection_source'=>'manual',
        ]);
        $shipment->overseaReview()->create([
            'status'=>'approved','reviewed_warehouse_id'=>$w->id,'reviewed_at'=>now(),
        ]);
        WarehouseOpenApiConnection::query()->create([
            'warehouse_id'=>$w->id,'provider'=>'xlwms',
            'app_key_encrypted'=>'TEST-APPKEY-1234',
            'app_secret_encrypted'=>'TEST-SECRET-1234',
            'oms_warehouse_code'=>'OMS-US-SM',
            'last_verified_at'=>now(),
        ]);
        return $o->fresh();
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
        $this->assertSame('OMS-STANDARD', $p['payload']['logisticsChannel']);
        $this->assertSame('SAME-SKU', $p['payload']['productList'][0]['sku']);
        $this->assertSame(1, $p['payload']['isSubmit']);
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
                ['channelCode'=>'OMS-STANDARD','whCodes'=>['OMS-US-SM']],
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
        $this->assertSame('submitted_oms',$r['state']);
        $this->assertSame('OMS-FIRST',$r['oms_order_no']);
        Http::assertSentCount(6);
        Http::assertSent(fn($req)=>str_contains($req->url(),'outboundOrder/create')
            && (int) (is_object($req['data'][0]) ? $req['data'][0]->isSubmit : $req['data'][0]['isSubmit']) === 1
            && (is_object($req['data'][0]) ? $req['data'][0]->thirdOrderNo : $req['data'][0]['thirdOrderNo']) === 'BYL-ERP-'.$o->id);
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
                ['channelCode'=>'OMS-STANDARD','whCodes'=>['OMS-US-SM']],
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
            'state'=>'submitted_oms',
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
        $this->assertSame('submitted_oms',$attempt->fresh()->state);
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
        $this->assertSame('submitted_oms',$a->fresh()->state);
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
        $this->assertSame('submitted_oms',$a->fresh()->state);
        Http::assertSentCount(1);
        Http::assertNotSent(fn($r)=>str_contains($r->url(),'outboundOrder/create'));
    }

}
