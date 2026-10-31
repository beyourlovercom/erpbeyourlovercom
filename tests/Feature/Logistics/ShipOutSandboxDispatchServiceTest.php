<?php

namespace Tests\Feature\Logistics;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\ShipOut\ShipOutSandboxDispatchService;
use App\Modules\Logistics\Application\ShipOut\ShipOutProviderSkuStockVerifier;
use App\Modules\Logistics\Application\ShipOut\ShipOutSandboxLabelStage;
use App\Modules\Logistics\Application\ShipOut\ShipOutOutboundPayloadFactory;
use App\Modules\Products\Infrastructure\Models\ProductSkuAlias;
use App\Modules\Products\Application\SkuMatchKey;
use App\Modules\Logistics\Application\ShipOut\ShipOutTrackingApplyService;
use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutReadOnlyGateway;
use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutSandboxOutboundTransport;
use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
use App\Modules\Logistics\Infrastructure\Models\Carrier;
use App\Modules\Logistics\Infrastructure\Models\CarrierService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

final class ShipOutSandboxDispatchServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('shipout.sandbox_inventory_preflight_enabled',false);
        config()->set('shipout.sandbox_enabled_modes','9');
        config()->set('shipout.sandbox_service_group_id','');
        config()->set('shipout.sandbox_client_org_id','');
        // Only synthetic fake provider fixture has an explicit UTC contract.
        // Production default remains empty and MUST fail closed.
        config()->set('shipout.sandbox_shipped_time_timezone','UTC');
        // Isolate route behavior from other tests sharing the same test
        // rate-limit cache. Real web middleware remains enforced.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        // Deliberately synthetic credentials; connector isn't runtime-bound yet.
        $this->app->bind(ShipOutReadOnlyGateway::class,
            fn () => new ShipOutReadOnlyGateway('test-key', 'test-secret', 'test-token'));
        $this->app->bind(ShipOutSandboxOutboundTransport::class,
            fn () => new ShipOutSandboxOutboundTransport('test-key', 'test-secret', 'test-token'));
    }

    private function order(bool $approved = true): Order
    {
        $wh = Warehouse::query()->updateOrCreate(['code' => 'US-LA'], [
            'slug' => 'us-la', 'name' => 'MorePlus',
            'type' => 'warehouse', 'is_active' => true, 'priority' => 10,
        ]);
        $sku = Product::query()->create([
            'sku' => 'SHIPOUT-LOCAL-TEST', 'name' => 'Sandbox Product',
            'product_type' => 'simple', 'is_active' => true,
        ]);
        $o = Order::query()->create([
            'internal_order_number' => 'TEST-ORDER-UNIQUE',
            'erp_order_id' => '007-1029-c99999',
            'source_type' => 'manual_other', 'order_kind' => 'customer',
            'external_order_number' => 'SOURCE-99999',
            'order_date' => '2026-10-29', 'order_status' => 'pending_shipment',
        ]);
        $o->address()->create([
            'recipient_name' => 'TEST ONLY', 'phone' => '+1 555 0199',
            'email' => 'sandbox@example.test', 'country' => 'US',
            'province_state' => 'CA', 'city' => 'Test City',
            'address_line_1' => '100 Example Way', 'postal_code' => '90210',
        ]);
        $o->items()->create([
            'product_id' => $sku->id, 'sku' => $sku->sku,
            'product_summary' => 'Sandbox Product', 'quantity' => 1,
            'unit_price' => '1.00', 'line_revenue' => '1.00',
        ]);
        $shipment = $o->shipment()->create([
            'warehouse_id' => $wh->id,
            'warehouse_selection_source' => 'manual',
            'shipment_status' => 'pending', 'carrier' => 'USPS',
        ]);
        $user = User::factory()->create();
        OverseaWarehouseReview::query()->create([
            'order_id' => $o->id,
            'submitted_warehouse_id' => $wh->id,
            'reviewed_warehouse_id' => $approved ? $wh->id : null,
            'status' => $approved ? 'approved' : 'pending',
            'reviewed_by' => $approved ? $user->id : null,
            'reviewed_at' => $approved ? now() : null,
        ]);
        return $o;
    }

    private function enable(): void
    {
        config()->set('shipout.sandbox_read_enabled', true);
        config()->set('shipout.sandbox_dispatch_enabled', true);
        config()->set('shipout.sandbox_order_allowlist', 'C99999-007');
        config()->set('shipout.sandbox_warehouse_id', 'TEST-W');
    }

    public function test_r8_production_never_calls_sandbox_even_if_someone_sets_all_flags_on(): void
    {
        $this->enable();
        config()->set('shipout.sandbox_enabled_modes','6,9,10');
        config()->set('shipout.sandbox_tracking_apply_enabled',true);
        config()->set('shipout.sandbox_app_key','synthetic-r8-key');
        config()->set('shipout.sandbox_app_secret','synthetic-r8-secret');
        config()->set('shipout.sandbox_bearer','synthetic-r8-bearer');
        $o=$this->order();
        $this->acknowledgedAttempt($o);
        Http::fake();
        Http::preventStrayRequests();
        $env=$this->app['env'];
        $this->app->instance('env','production');
        try {
            $calls=[
                fn()=>app(ShipOutSandboxLabelStage::class)->stage(
                    $o->id,12345,$this->fakePdfLabel(),'9400PRODUCTIONBLOCKED12345',1
                ),
                fn()=>app(ShipOutReadOnlyGateway::class)->warehouses(),
                fn()=>app(ShipOutSandboxDispatchService::class)
                    ->dispatchApprovedOrder($o->id,'2026-10-30'),
                fn()=>app(ShipOutTrackingApplyService::class)
                    ->apply($o->id,'9400SHOULDNOTAPPLY',
                        $this->warehouseUser(['logistics.operate'])),
            ];
            foreach ($calls as $call) {
                $caught=false;
                try{$call();}
                catch(\RuntimeException $e){
                    $caught=true;
                    $this->assertSame('SHIPOUT_SANDBOX_FORBIDDEN_IN_PRODUCTION',$e->getMessage());
                }
                $this->assertTrue($caught,'Production sandbox egress must be hard denied');
            }
            Http::assertNothingSent();
            $this->assertDatabaseCount('shipout_label_stages',0);
            $this->assertSame('pending',$o->fresh()->shipment->shipment_status);
            $this->assertNull($o->fresh()->shipment->tracking_number);
        } finally {
            $this->app->instance('env',$env);
        }
    }

    public function test_r10_lowercase_usla_is_accepted_for_preflight_without_provider_egress(): void
    {
        $this->enable();
        $o = $this->order();
        $o->shipment->warehouse->update(['code' => 'us-la']);
        Http::fake();
        Http::preventStrayRequests();
        $this->actingAs($this->warehouseUser(['logistics.view', 'logistics.operate']));
        $response = $this->getJson("/logistics/oversea/us-moreplus/orders/{$o->id}/shipout/preflight?mode=9");
        $response->assertOk()->assertJsonPath('ready', true)
            ->assertJsonPath('order_no', 'C99999-007');
        Http::assertNothingSent();
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
    }

    public function test_r10_non_usla_warehouse_remains_blocked(): void
    {
        $this->enable();
        $o = $this->order();
        $o->shipment->warehouse->update(['code' => 'us-ny']);
        Http::fake();
        Http::preventStrayRequests();
        $this->actingAs($this->warehouseUser(['logistics.view', 'logistics.operate']));
        $this->getJson("/logistics/oversea/us-moreplus/orders/{$o->id}/shipout/preflight?mode=9")
            ->assertNotFound();
        Http::assertNothingSent();
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
    }

    public function test_approved_order_is_submitted_once_and_second_submission_is_blocked(): void
    {
        $this->enable();
        $o = $this->order();
        Http::fake(fn ($request) => $request->method() === 'GET'
            ? Http::response(['result'=>'OK','data'=>['records'=>[],'total'=>0]], 200)
            : Http::response([['result'=>'OK', 'data'=>[
                'sid'=>1,'orderNO'=>'C99999-007','orderId'=>'PROVIDER-TEST-123',
            ]]], 200));
        Http::preventStrayRequests();

        $service = app(ShipOutSandboxDispatchService::class);
        $result = $service->dispatchApprovedOrder($o->id, '2026-10-30');
        $this->assertSame('acknowledged', $result['status']);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && ($request->data()[0]['shipmentForms'][0]['shippingInfo']['carrierId'] ?? null) === 9);
        $this->assertDatabaseHas('shipout_dispatch_attempts', [
            'order_id' => $o->id, 'status' => 'acknowledged',
            'provider_order_id' => 'PROVIDER-TEST-123',
        ]);
        $this->expectExceptionMessage('SHIPOUT_DUPLICATE_ATTEMPT_BLOCKED');
        try {
            $service->dispatchApprovedOrder($o->id, '2026-10-30');
        } finally {
            Http::assertSentCount(3);
            Http::assertSent(fn ($request): bool => $request->method() === 'POST'
                && $request->url() === 'https://opendev.shipout.com/api/open-api/oms/order/batchSubmitAsync');
        }
    }

    public function test_operator_selected_verified_service_group_10_uses_exact_id_and_is_single_submit(): void
    {
        $this->enable();
        config()->set('shipout.sandbox_enabled_modes', '9,10');
        config()->set('shipout.sandbox_service_group_id', '1993981773496696833');
        $o = $this->order();
        Http::fake(fn ($request) => $request->method() === 'GET'
            ? Http::response(['result'=>'OK','data'=>['records'=>[],'total'=>0]],200)
            : Http::response([['result'=>'OK','data'=>[
                'sid'=>1,'orderNO'=>'C99999-007','orderId'=>'PROVIDER-MODE-10',
            ]]],200));
        Http::preventStrayRequests();
        $result = app(ShipOutSandboxDispatchService::class)
            ->dispatchApprovedOrder($o->id, '2026-10-30', null, '10');
        $this->assertSame('acknowledged', $result['status']);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && ($request->data()[0]['shipmentForms'][0]['outboundInfo']['sysServiceId'] ?? null) === '10'
            && ($request->data()[0]['shipmentForms'][0]['outboundInfo']['serviceGroupId'] ?? null) === '1993981773496696833'
            && ($request->data()[0]['shipmentForms'][0]['shippingInfo']['carrierId'] ?? null) === 1);
        $this->assertDatabaseHas('shipout_dispatch_attempts', [
            'order_id'=>$o->id,'status'=>'acknowledged'
        ]);
        $this->expectExceptionMessage('SHIPOUT_DUPLICATE_ATTEMPT_BLOCKED');
        try {
            app(ShipOutSandboxDispatchService::class)
                ->dispatchApprovedOrder($o->id, '2026-10-30', null, '10');
        } finally {
            Http::assertSentCount(3);
        }
    }

    public function test_mode_10_disabled_or_unconfigured_never_calls_provider(): void
    {
        $this->enable();
        $o=$this->order();
        Http::fake();
        $this->expectExceptionMessage('SHIPOUT_SHIPPING_MODE_NOT_ENABLED');
        try {
            app(ShipOutSandboxDispatchService::class)
                ->dispatchApprovedOrder($o->id, '2026-10-30', null, '10');
        } finally {
            Http::assertNothingSent();
            $this->assertDatabaseCount('shipout_dispatch_attempts',0);
        }
    }

    public function test_dede_preflight_mode_9_fingerprint_cannot_be_reused_for_mode_10(): void
    {
        $this->enable();
        config()->set('shipout.sandbox_enabled_modes', '9,10');
        config()->set('shipout.sandbox_service_group_id', '1993981773496696833');
        $o = $this->order();
        $this->actingAs($this->warehouseUser(['logistics.view','logistics.operate']));
        $pre = $this->getJson("/logistics/oversea/us-moreplus/orders/{$o->id}/shipout/preflight?mode=9")
            ->assertOk()->assertJsonPath('ready', true)
            ->assertJsonPath('shipping_mode', '9')->json();
        Http::fake(['https://opendev.shipout.com/api/*' =>
            Http::response(['result'=>'OK','data'=>['total'=>0,'records'=>[]]],200)]);
        Http::preventStrayRequests();
        $this->postJson("/logistics/oversea/us-moreplus/orders/{$o->id}/shipout/send",[
            'ship_date'=>$pre['ship_date'],
            'shipping_mode'=>'10',
            'payload_hash'=>$pre['payload_hash'],
            'confirmation'=>'SUBMIT_US_LA_ORDER_'.$o->id,
        ])->assertStatus(409)->assertJsonPath('error_code','SHIPOUT_PREFLIGHT_STALE');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method()==='GET');
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
    }

    public function test_mode_10_requires_verified_group_id_before_provider_call(): void
    {
        $this->enable();
        config()->set('shipout.sandbox_enabled_modes','9,10');
        $o=$this->order();
        Http::fake();
        $this->expectExceptionMessage('SHIPOUT_SERVICE_GROUP_NOT_VERIFIED');
        try {
            app(ShipOutSandboxDispatchService::class)
                ->dispatchApprovedOrder($o->id, '2026-10-30', null, '10');
        } finally {
            Http::assertNothingSent();
        }
    }

    private function fakePdfLabel(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('sandbox-label.pdf',
            "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n".str_repeat('X',120)."\n%%EOF\n");
    }

    public function test_mode6_stages_encrypted_pdf_then_preflights_and_submits_once(): void
    {
        $this->enable();
        config()->set('shipout.sandbox_enabled_modes','6,9');
        $o=$this->order();
        $actor=$this->warehouseUser(['logistics.view','logistics.operate']);
        $this->actingAs($actor);
        $stage=$this->post("/logistics/oversea/us-moreplus/orders/{$o->id}/shipout/label-stage",[
            'label'=>$this->fakePdfLabel(),
            'tracking_number'=>'9400TESTLABEL1234',
            'carrier_id'=>1,
        ], ['Accept'=>'application/json'])
            ->assertOk()->assertJsonPath('tracking_number','9400TESTLABEL1234')->json();
        $token=$stage['label_token'];
        $this->assertSame(64,strlen($token));
        $encrypted=DB::table('shipout_label_stages')->where('order_id',$o->id)->first();
        $this->assertNotNull($encrypted);
        $this->assertStringNotContainsString('%PDF-', $encrypted->encrypted_pdf_b64);
        $this->assertStringNotContainsString('9400TESTLABEL1234',$encrypted->encrypted_pdf_b64);
        Http::fake(fn ($req)=> $req->method()==='GET'
            ? Http::response(['result'=>'OK','data'=>['records'=>[],'total'=>0]],200)
            : Http::response([['result'=>'OK','data'=>[
                'sid'=>1,'orderNO'=>'C99999-007','orderId'=>'PROVIDER-MODE6-123',
            ]]],200));
        Http::preventStrayRequests();
        $pre=$this->postJson("/logistics/oversea/us-moreplus/orders/{$o->id}/shipout/preflight",[
            'mode'=>'6','label_token'=>$token,
        ])
            ->assertOk()->assertJsonPath('ready',true)->assertJsonPath('shipping_mode','6')->json();
        $this->postJson("/logistics/oversea/us-moreplus/orders/{$o->id}/shipout/send",[
            'ship_date'=>$pre['ship_date'],'shipping_mode'=>'6',
            'label_token'=>$token,'payload_hash'=>$pre['payload_hash'],
            'confirmation'=>'SUBMIT_US_LA_ORDER_'.$o->id,
        ])->assertOk()->assertJsonPath('status','acknowledged');
        Http::assertSent(fn($req)=>$req->method()==='POST'
            && ($req->data()[0]['shipmentForms'][0]['outboundInfo']['sysServiceId']??null)==='6'
            && ($req->data()[0]['shipmentForms'][0]['outboundInfo']['trackingNumber']??null)==='9400TESTLABEL1234'
            && str_starts_with(base64_decode($req->data()[0]['shipmentForms'][0]['outboundInfo']['shipmentOutboundLabel']['base64']),'%PDF-'));
        $this->assertNotNull(DB::table('shipout_label_stages')->where('order_id',$o->id)->value('used_at'));
        $this->assertDatabaseHas('shipout_dispatch_attempts',['order_id'=>$o->id,'status'=>'acknowledged']);
        $this->postJson("/logistics/oversea/us-moreplus/orders/{$o->id}/shipout/send",[
            'ship_date'=>$pre['ship_date'],'shipping_mode'=>'6',
            'label_token'=>$token,'payload_hash'=>$pre['payload_hash'],
            'confirmation'=>'SUBMIT_US_LA_ORDER_'.$o->id,
        ])->assertStatus(409);
        Http::assertSentCount(2);
    }

    public function test_mode6_rejects_pdf_invalid_and_read_only_role_cannot_upload(): void
    {
        $this->enable();
        config()->set('shipout.sandbox_enabled_modes','6,9');
        $o=$this->order();
        $this->actingAs($this->warehouseUser(['logistics.view','logistics.operate']));
        $this->post("/logistics/oversea/us-moreplus/orders/{$o->id}/shipout/label-stage",[
            'label'=>UploadedFile::fake()->createWithContent('wrong.pdf',"not pdf"),
            'tracking_number'=>'9400TESTLABEL1234','carrier_id'=>1,
        ],['Accept'=>'application/json'])->assertStatus(409)->assertJsonPath('error_code','SHIPOUT_LABEL_PDF_INVALID');
        $this->assertDatabaseCount('shipout_label_stages',0);
        $this->actingAs($this->warehouseUser(['logistics.view']));
        $this->post("/logistics/oversea/us-moreplus/orders/{$o->id}/shipout/label-stage",[
            'label'=>$this->fakePdfLabel(),'tracking_number'=>'9400TESTLABEL1234','carrier_id'=>1,
        ],['Accept'=>'application/json'])->assertForbidden();
        $this->assertDatabaseCount('shipout_label_stages',0);
    }

    public function test_label_token_get_query_is_never_accepted_or_logged_as_preflight(): void
    {
        $this->enable();
        config()->set('shipout.sandbox_enabled_modes','6,9');
        $o=$this->order();
        $this->actingAs($this->warehouseUser(['logistics.view','logistics.operate']));
        $this->getJson("/logistics/oversea/us-moreplus/orders/{$o->id}/shipout/preflight?mode=6&label_token=".str_repeat('a',64))
            ->assertStatus(405);
        $this->assertDatabaseCount('shipout_dispatch_attempts',0);
    }

    public function test_mode6_token_is_order_and_actor_bound(): void
    {
        $this->enable();
        config()->set('shipout.sandbox_enabled_modes','6,9');
        $o=$this->order();
        $actor=$this->warehouseUser(['logistics.view','logistics.operate']);
        $this->actingAs($actor);
        $token=$this->post("/logistics/oversea/us-moreplus/orders/{$o->id}/shipout/label-stage",[
            'label'=>$this->fakePdfLabel(),'tracking_number'=>'9400TESTLABEL1234','carrier_id'=>1,
        ],['Accept'=>'application/json'])->assertOk()->json('label_token');
        $this->actingAs($this->warehouseUser(['logistics.view','logistics.operate']));
        $response=$this->postJson("/logistics/oversea/us-moreplus/orders/{$o->id}/shipout/preflight",[
            'mode'=>'6','label_token'=>$token,
        ])
            ->assertOk();
        $this->assertFalse($response->json('ready'));
        $this->assertContains('SHIPOUT_LABEL_TOKEN_INVALID',$response->json('blockers'));
        $this->assertDatabaseCount('shipout_dispatch_attempts',0);
    }

    public function test_stock_preflight_resolves_approved_provider_alias_and_sufficient_available_qty(): void
    {
        $this->enable();
        config()->set('shipout.sandbox_inventory_preflight_enabled',true);
        config()->set('shipout.sandbox_client_org_id','TEST-CLIENT');
        $o=$this->order();
        $product=$o->items->first()->product_id;
        $alias=ProductSkuAlias::query()->create([
            'product_id'=>$product,'alias_sku'=>'MOREPLUS-UAT-PHYSICAL',
            'match_key'=>SkuMatchKey::compute('MOREPLUS-UAT-PHYSICAL'),
            'source_type'=>'manual',
        ]);
        $alias->forceFill(['is_active'=>true,'oms_verified_at'=>now(),
            'oms_identity_evidence'=>'SANDBOX VERIFIED'])->save();
        Http::fake(fn ($req) =>
            str_contains($req->url(),'/product/queryList')
            ? Http::response(['result'=>'OK','data'=>[
                'total'=>1,'records'=>[['omsSku'=>'MOREPLUS-UAT-PHYSICAL','skuId'=>'S1']],
            ]],200)
            : Http::response(['result'=>'OK','data'=>[
                'total'=>1,'records'=>[[
                    'warehouseId'=>'TEST-W','omsSku'=>'MOREPLUS-UAT-PHYSICAL',
                    'availableQuantity'=>3,'standardQuantity'=>4,
                ]],
            ]],200));
        Http::preventStrayRequests();
        $payload=app(ShipOutOutboundPayloadFactory::class)
            ->fromApprovedOrder($o,'TEST-W','2026-10-30','9');
        $verified=app(ShipOutProviderSkuStockVerifier::class)->verify($o,$payload,'TEST-W');
        $this->assertSame([['omsSku'=>'MOREPLUS-UAT-PHYSICAL','qty'=>1]],
            $verified[0]['shipmentForms'][0]['productList']);
        Http::assertSentCount(2);
    }

    public function test_stock_preflight_no_available_qty_fails_closed_without_dispatch(): void
    {
        $this->enable();
        config()->set('shipout.sandbox_inventory_preflight_enabled',true);
        config()->set('shipout.sandbox_client_org_id','TEST-CLIENT');
        $o=$this->order();
        Http::fake(fn ($req) =>
            str_contains($req->url(),'/product/queryList')
            ? Http::response(['result'=>'OK','data'=>[
                'total'=>1,'records'=>[['omsSku'=>'SHIPOUT-LOCAL-TEST','skuId'=>'S1']],
            ]],200)
            : Http::response(['result'=>'OK','data'=>[
                'total'=>1,'records'=>[[
                    'warehouseId'=>'TEST-W','omsSku'=>'SHIPOUT-LOCAL-TEST',
                    'availableQuantity'=>0,
                ]],
            ]],200));
        Http::preventStrayRequests();
        $this->expectExceptionMessage('SHIPOUT_PROVIDER_SKU_OUT_OF_STOCK');
        try {
            app(ShipOutSandboxDispatchService::class)->dispatchApprovedOrder(
                $o->id,'2026-10-30'
            );
        } finally {
            Http::assertSentCount(2);
            $this->assertDatabaseCount('shipout_dispatch_attempts',0);
        }
    }

    public function test_stock_preflight_missing_client_org_fails_before_provider_request(): void
    {
        $this->enable();
        config()->set('shipout.sandbox_inventory_preflight_enabled',true);
        config()->set('shipout.sandbox_client_org_id','');
        // Only synthetic fake provider fixture has an explicit UTC contract.
        // Production default remains empty and MUST fail closed.
        config()->set('shipout.sandbox_shipped_time_timezone','UTC');
        $o=$this->order();
        Http::fake();
        $this->expectExceptionMessage('SHIPOUT_STOCK_IDENTITY_UNAVAILABLE');
        try {
            app(ShipOutSandboxDispatchService::class)->dispatchApprovedOrder($o->id,'2026-10-30');
        } finally { Http::assertNothingSent(); }
    }

    public function test_mode6_expired_label_token_blocks_preflight_and_uses_no_api(): void
    {
        $this->enable();
        config()->set('shipout.sandbox_enabled_modes','6,9');
        $o=$this->order();
        $this->actingAs($this->warehouseUser(['logistics.view','logistics.operate']));
        $token=$this->post("/logistics/oversea/us-moreplus/orders/{$o->id}/shipout/label-stage",[
            'label'=>$this->fakePdfLabel(),'tracking_number'=>'9400TESTLABEL1234','carrier_id'=>1,
        ],['Accept'=>'application/json'])->assertOk()->json('label_token');
        DB::table('shipout_label_stages')->where('order_id',$o->id)
            ->update(['expires_at'=>now()->subMinute()]);
        Http::fake();
        $res=$this->postJson("/logistics/oversea/us-moreplus/orders/{$o->id}/shipout/preflight",[
            'mode'=>'6','label_token'=>$token,
        ])->assertOk()->assertJsonPath('ready',false);
        $this->assertContains('SHIPOUT_LABEL_TOKEN_INVALID',$res->json('blockers'));
        Http::assertNothingSent();
        $this->assertDatabaseCount('shipout_dispatch_attempts',0);
    }

    public function test_mode6_overlimit_pdf_is_rejected_without_storing_data(): void
    {
        $this->enable();
        config()->set('shipout.sandbox_enabled_modes','6,9');
        $o=$this->order();
        $this->actingAs($this->warehouseUser(['logistics.view','logistics.operate']));
        $large="%PDF-1.4\n".str_repeat('X',513*1024)."\n%%EOF\n";
        $this->expectExceptionMessage('SHIPOUT_LABEL_PDF_INVALID');
        try {
            app(ShipOutSandboxLabelStage::class)->stage(
                $o->id, 12345, UploadedFile::fake()->createWithContent('large.pdf',$large),
                '9400TESTLABEL1234',1
            );
        } finally {
            $this->assertDatabaseCount('shipout_label_stages',0);
        }
    }

    public function test_stock_preflight_two_line_order_blocks_if_one_sku_has_insufficient_stock(): void
    {
        $this->enable();
        config()->set('shipout.sandbox_inventory_preflight_enabled',true);
        config()->set('shipout.sandbox_client_org_id','TEST-CLIENT');
        $o=$this->order();
        $second=Product::query()->create([
            'sku'=>'SHIPOUT-SECOND-TEST','name'=>'Second Product',
            'product_type'=>'simple','is_active'=>true,
        ]);
        $o->items()->create([
            'product_id'=>$second->id,'sku'=>$second->sku,
            'product_summary'=>'Second Product','quantity'=>2,
            'unit_price'=>'1.00','line_revenue'=>'2.00',
        ]);
        Http::fake(fn($req)=>str_contains($req->url(),'/product/queryList')
            ? Http::response(['result'=>'OK','data'=>[
                'total'=>2,'records'=>[
                    ['omsSku'=>'SHIPOUT-LOCAL-TEST','skuId'=>'S1'],
                    ['omsSku'=>'SHIPOUT-SECOND-TEST','skuId'=>'S2'],
                ],
            ]],200)
            : Http::response(['result'=>'OK','data'=>[
                'total'=>2,'records'=>[
                    ['warehouseId'=>'TEST-W','omsSku'=>'SHIPOUT-LOCAL-TEST','availableQuantity'=>5],
                    ['warehouseId'=>'TEST-W','omsSku'=>'SHIPOUT-SECOND-TEST','availableQuantity'=>1],
                ],
            ]],200));
        Http::preventStrayRequests();
        $this->expectExceptionMessage('SHIPOUT_PROVIDER_SKU_OUT_OF_STOCK');
        try {
            app(ShipOutSandboxDispatchService::class)->dispatchApprovedOrder($o->id,'2026-10-30');
        } finally {
            Http::assertSentCount(2);
            $this->assertDatabaseCount('shipout_dispatch_attempts',0);
        }
    }

    public function test_dede_unapproved_order_blocks_before_any_api_call(): void
    {
        $this->enable();
        $o = $this->order(false);
        Http::fake();
        $this->expectExceptionMessage('SHIPOUT_ERP_APPROVAL_OR_WAREHOUSE_BLOCKED');
        try {
            app(ShipOutSandboxDispatchService::class)->dispatchApprovedOrder($o->id, '2026-10-30');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_timeout_remains_uncertain_and_never_automatically_retries(): void
    {
        $this->enable();
        $o = $this->order();
        Http::fake(fn ($request) => $request->method() === 'GET'
            ? Http::response(['result'=>'OK','data'=>['records'=>[],'total'=>0]], 200)
            : Http::response(['not'=>'success'], 503));
        $service = app(ShipOutSandboxDispatchService::class);
        try {
            $service->dispatchApprovedOrder($o->id, '2026-10-30');
            $this->fail('503 cannot be accepted.');
        } catch (\RuntimeException $e) {
            $this->assertSame('SHIPOUT_SUBMISSION_OUTCOME_UNKNOWN', $e->getMessage());
        }
        $this->assertDatabaseHas('shipout_dispatch_attempts', [
            'order_id' => $o->id, 'status' => 'uncertain',
        ]);
        $this->expectExceptionMessage('SHIPOUT_DUPLICATE_ATTEMPT_BLOCKED');
        try {
            $service->dispatchApprovedOrder($o->id, '2026-10-30');
        } finally {
            Http::assertSentCount(3);
        }
    }
    public function test_shipout_status_readback_returns_shipped_tracking_without_erp_or_woo_write(): void
    {
        $o = $this->order();
        $this->enable();
        $this->actingAs($this->warehouseUser(['logistics.view']));
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/order/queryListV2?')) {
                return Http::response([
                    'result' => 'OK',
                    'data' => ['total' => 1, 'records' => [[
                        'orderId' => 'OMS-123', 'orderNO' => 'C99999-007', 'status' => 4,
                        'shipmentList' => [[
                            'warehouseId' => 'TEST-W', 'trackingNumber' => '9400READBACK',
                            'outboundStatus' => 4, 'shippedTime' => '2026-10-29 10:00:00',
                            'carrierId' => 1,
                        ]],
                    ]]],
                ], 200);
            }
            return Http::response([
                'result' => 'OK',
                'data' => [
                    'orderId' => 'OMS-123', 'orderNO' => 'C99999-007', 'status' => 4,
                    'toAddress' => ['name' => 'DO NOT LEAK'],
                    'orderShipments' => [[
                        'warehouseId' => 'TEST-W', 'shipmentId' => 'SHIP-123',
                        'trackingNumber' => '9400READBACK', 'outboundStatus' => 4,
                        'labelStatus' => 3, 'shippedTime' => '2026-10-29 10:00:00',
                        'carrierId' => 1,
                    ]],
                ],
            ], 200);
        });
        Http::preventStrayRequests();

        $this->postJson("/logistics/oversea/us-moreplus/orders/{$o->id}/shipout/status", [])
            ->assertOk()
            ->assertJsonPath('state', 'ready_for_erp_review')
            ->assertJsonPath('tracking_number', '9400READBACK')
            ->assertJsonPath('carrier_id', 1)
            ->assertJsonPath('erp_shipment_unchanged', true)
            ->assertJsonPath('woo_unchanged', true)
            ->assertDontSee('DO NOT LEAK');
        Http::assertSentCount(2);
        $this->assertNull($o->fresh()->shipment->tracking_number);
        $this->assertSame('pending', $o->fresh()->shipment->shipment_status);
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
    }

    public function test_shipout_sandbox_cancelled_for_insufficient_credit_is_visible_without_sensitive_text(): void
    {
        $o = $this->order();
        $this->enable();
        $this->actingAs($this->warehouseUser(['logistics.view']));
        Http::fake(function ($request) {
            $d = [
                'orderId' => 'OMS-CANCELED', 'orderNO' => 'C99999-007',
                'status' => 6,
                'cancelReason' => 'Account Balance or Credit insufficient, Please add balance before proceed TRACESECRET',
                'shipmentList' => [[
                    'warehouseId' => null, 'outboundStatus' => 0,
                    'labelStatus' => 1, 'trackingNumber' => null,
                ]],
            ];
            if (! str_contains($request->url(), '/queryListV2?')) {
                $d['orderShipments'] = $d['shipmentList'];
                unset($d['shipmentList']);
            }
            return Http::response(['result' => 'OK', 'data' =>
                str_contains($request->url(), '/queryListV2?')
                    ? ['total' => 1, 'records' => [$d]] : $d], 200);
        });
        Http::preventStrayRequests();
        $this->postJson("/logistics/oversea/us-moreplus/orders/{$o->id}/shipout/status", [])
            ->assertOk()
            ->assertJsonPath('state', 'blocked')
            ->assertJsonPath('reason', 'PROVIDER_ACCOUNT_BALANCE_INSUFFICIENT')
            ->assertJsonPath('provider_order_status', 6)
            ->assertJsonPath('erp_shipment_unchanged', true)
            ->assertDontSee('TRACESECRET');
        $this->assertSame('pending', $o->fresh()->shipment->shipment_status);
        $this->assertNull($o->fresh()->shipment->tracking_number);
        Http::assertSentCount(2);
    }

    public function test_shipout_status_readback_rejects_cross_warehouse_shipment(): void
    {
        $o = $this->order();
        $this->enable();
        $this->actingAs($this->warehouseUser(['logistics.view']));
        Http::fake(fn ($request) => str_contains($request->url(), '/queryListV2?')
            ? Http::response(['result' => 'OK', 'data' => ['total' => 1, 'records' => [[
                'orderId' => 'OMS-123', 'orderNO' => 'C99999-007', 'status' => 4, 'shipmentList' => [],
            ]]]], 200)
            : Http::response(['result' => 'OK', 'data' => [
                'orderId' => 'OMS-123', 'orderNO' => 'C99999-007', 'status' => 4,
                'orderShipments' => [[
                    'warehouseId' => 'OTHER-WH', 'outboundStatus' => 4,
                    'trackingNumber' => '9400WRONG', 'shippedTime' => '2026-10-29 10:00:00',
                    'carrierId' => 1,
                ]],
            ]], 200));
        $this->postJson("/logistics/oversea/us-moreplus/orders/{$o->id}/shipout/status", [])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'SHIPOUT_SHIPMENT_WAREHOUSE_MISMATCH')
            ->assertJsonPath('retry_same_order', false);
        $this->assertNull($o->fresh()->shipment->tracking_number);
    }

    private function acknowledgedAttempt(Order $order): void
    {
        DB::table('shipout_dispatch_attempts')->insert([
            'order_id' => $order->id,
            'order_no' => 'C99999-007',
            'provider_warehouse_id' => 'TEST-W',
            'payload_hash' => str_repeat('a', 64),
            'status' => 'acknowledged',
            'provider_order_id' => 'OMS-123',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function fakeShippedProvider(string $tracking = '9400READBACK', int $status = 4, int $carrierId = 1): void
    {
        Http::fake(fn ($request) => str_contains($request->url(), '/queryListV2?')
            ? Http::response(['result' => 'OK', 'data' => ['total' => 1, 'records' => [[
                'orderId' => 'OMS-123', 'orderNO' => 'C99999-007',
                'status' => $status, 'shipmentList' => [],
            ]]]], 200)
            : Http::response(['result' => 'OK', 'data' => [
                'orderId' => 'OMS-123', 'orderNO' => 'C99999-007', 'status' => $status,
                'orderShipments' => [[
                    'warehouseId' => 'TEST-W', 'shipmentId' => 'SHIP-123',
                    'outboundStatus' => $status === 4 ? 4 : 3, 'labelStatus' => 3,
                    'trackingNumber' => $tracking, 'shippedTime' => '2026-10-29 10:00:00',
                    'carrierId' => $carrierId,
                ]],
            ]], 200));
    }

    public function test_tracking_apply_off_by_default_does_not_touch_erp_or_provider(): void
    {
        $o = $this->order();
        $this->enable();
        $this->acknowledgedAttempt($o);
        config()->set('shipout.sandbox_tracking_apply_enabled', false);
        Http::fake();
        $actor = $this->warehouseUser(['logistics.operate']);
        $this->expectExceptionMessage('SHIPOUT_TRACKING_APPLY_DISABLED');
        try {
            app(ShipOutTrackingApplyService::class)->apply($o->id, '9400READBACK', $actor);
        } finally {
            Http::assertNothingSent();
            $this->assertSame('pending', $o->fresh()->shipment->shipment_status);
        }
    }

    public function test_tracking_apply_refuses_mismatched_tracking_and_never_changes_erp(): void
    {
        $o = $this->order();
        $this->enable();
        config()->set('shipout.sandbox_tracking_apply_enabled', true);
        $this->acknowledgedAttempt($o);
        $this->fakeShippedProvider('9400READBACK');
        $actor = $this->warehouseUser(['logistics.operate']);
        $this->expectExceptionMessage('SHIPOUT_TRACKING_CONFIRMATION_MISMATCH');
        try {
            app(ShipOutTrackingApplyService::class)->apply($o->id, '9400WRONG', $actor);
        } finally {
            $this->assertNull($o->fresh()->shipment->tracking_number);
            $this->assertDatabaseHas('shipout_dispatch_attempts', [
                'order_id' => $o->id, 'status' => 'acknowledged',
            ]);
        }
    }

    public function test_tracking_apply_requires_actual_shipped_state_not_async_acceptance(): void
    {
        $o = $this->order();
        $this->enable();
        config()->set('shipout.sandbox_tracking_apply_enabled', true);
        $this->acknowledgedAttempt($o);
        $this->fakeShippedProvider('9400READBACK', 3);
        $actor = $this->warehouseUser(['logistics.operate']);
        $this->expectExceptionMessage('SHIPOUT_NOT_CONFIRMED_SHIPPED');
        try {
            app(ShipOutTrackingApplyService::class)->apply($o->id, '9400READBACK', $actor);
        } finally {
            $this->assertSame('pending', $o->fresh()->shipment->shipment_status);
        }
    }

    public function test_tracking_apply_fails_closed_without_provider_time_zone_contract(): void
    {
        $o=$this->order();
        $this->enable();
        config()->set('shipout.sandbox_tracking_apply_enabled',true);
        config()->set('shipout.sandbox_shipped_time_timezone','');
        $this->acknowledgedAttempt($o);
        $this->fakeShippedProvider();
        Http::preventStrayRequests();
        $this->expectExceptionMessage('SHIPOUT_SHIPPED_TIME_TIMEZONE_UNVERIFIED');
        try {
            app(ShipOutTrackingApplyService::class)->apply(
                $o->id,'9400READBACK',$this->warehouseUser(['logistics.operate'])
            );
        } finally {
            $this->assertSame('pending',$o->fresh()->shipment->shipment_status);
            $this->assertNull($o->fresh()->shipment->tracking_number);
            $this->assertDatabaseHas('shipout_dispatch_attempts',[
                'order_id'=>$o->id,'status'=>'acknowledged',
            ]);
            Http::assertSentCount(2);
        }
    }

    public function test_provider_wall_clock_conversion_uses_only_explicit_test_timezone(): void
    {
        $o=$this->order();
        $this->enable();
        config()->set('shipout.sandbox_tracking_apply_enabled',true);
        config()->set('shipout.sandbox_shipped_time_timezone','Asia/Shanghai');
        $this->acknowledgedAttempt($o);
        $this->fakeShippedProvider();
        Http::preventStrayRequests();
        $result=app(ShipOutTrackingApplyService::class)->apply(
            $o->id,'9400READBACK',$this->warehouseUser(['logistics.operate'])
        );
        $this->assertSame('pending_vita',$result['woo_sync']);
        $this->assertSame('2026-10-29 02:00:00',
            $o->fresh()->shipment->shipped_at->utc()->format('Y-m-d H:i:s'));
        Http::assertSentCount(2);
    }

    public function test_confirmed_shipped_tracking_applies_to_erp_only_and_vita_sync_is_pending(): void
    {
        $o = $this->order();
        $this->enable();
        config()->set('shipout.sandbox_tracking_apply_enabled', true);
        $this->acknowledgedAttempt($o);
        $this->fakeShippedProvider();
        Http::preventStrayRequests();
        $actor = $this->warehouseUser(['logistics.view','logistics.operate']);
        $result = app(ShipOutTrackingApplyService::class)->apply($o->id, '9400READBACK', $actor);
        $this->assertSame('erp_shipment_updated', $result['status']);
        $this->assertSame('pending_vita', $result['woo_sync']);
        $this->assertDatabaseHas('shipout_dispatch_attempts', [
            'order_id' => $o->id, 'status' => 'shipped_applied',
        ]);
        $o->refresh();
        $this->assertSame('shipped', $o->shipment->shipment_status);
        $this->assertSame('9400READBACK', $o->shipment->tracking_number);
        $this->assertSame('shipped', $o->order_status->value);
        Http::assertSentCount(2);
    }

    public function test_warehouse_selected_ups_tracking_preserves_actual_carrier_not_requested_usps(): void
    {
        $o = $this->order();
        $this->enable();
        config()->set('shipout.sandbox_tracking_apply_enabled', true);
        $this->acknowledgedAttempt($o);
        $this->fakeShippedProvider('1ZREADBACK123456', 4, 2);
        // Carrier-specific service configuration must exist; never reuse USPS
        // service when ShipOut actually selected UPS.
        $ups = Carrier::query()->updateOrCreate(['name' => 'UPS'], [
            'name_en' => 'UPS', 'name_zh' => 'UPS', 'is_active' => true,
        ]);
        CarrierService::query()->updateOrCreate(['carrier_id' => $ups->id, 'service_key' => 'ground'], [
            'carrier_id' => $ups->id, 'service_key' => 'ground', 'service_value' => 'Ground',
            'name_en' => 'UPS Ground', 'is_active' => true, 'sort_order' => 1,
        ]);
        Http::preventStrayRequests();
        $r = app(ShipOutTrackingApplyService::class)->apply(
            $o->id, '1ZREADBACK123456', $this->warehouseUser(['logistics.operate'])
        );
        $this->assertSame('pending_vita', $r['woo_sync']);
        $this->assertSame('UPS', $o->fresh()->shipment->carrier);
        $this->assertSame('1ZREADBACK123456', $o->fresh()->shipment->tracking_number);
        $this->assertSame('shipped', $o->fresh()->shipment->shipment_status);
        Http::assertSentCount(2);
    }

    public function test_mock_provider_tracking_is_never_promoted_to_real_erp_or_woo(): void
    {
        $o = $this->order();
        $this->enable();
        config()->set('shipout.sandbox_tracking_apply_enabled', true);
        $this->acknowledgedAttempt($o);
        $this->fakeShippedProvider('mock_2_UNSHIPPABLE', 4, 2);
        Http::preventStrayRequests();
        $this->expectExceptionMessage('SHIPOUT_SYNTHETIC_TRACKING_NOT_PUBLISHABLE');
        try {
            app(ShipOutTrackingApplyService::class)->apply(
                $o->id, 'mock_2_UNSHIPPABLE', $this->warehouseUser(['logistics.operate'])
            );
        } finally {
            $this->assertSame('pending', $o->fresh()->shipment->shipment_status);
            $this->assertNull($o->fresh()->shipment->tracking_number);
            $this->assertDatabaseHas('shipout_dispatch_attempts', [
                'order_id'=>$o->id, 'status'=>'acknowledged',
            ]);
        }
    }

    public function test_dede_confirmed_tracking_apply_route_sets_erp_and_leaves_woo_pending(): void
    {
        $o = $this->order();
        $this->enable();
        config()->set('shipout.sandbox_tracking_apply_enabled', true);
        $this->acknowledgedAttempt($o);
        $this->fakeShippedProvider();
        Http::preventStrayRequests();
        $user = $this->warehouseUser(['logistics.view','logistics.operate']);
        $this->actingAs($user)->postJson(
            "/logistics/oversea/us-moreplus/orders/{$o->id}/shipout/apply",
            [
                'tracking_number' => '9400READBACK',
                'confirmation' => 'APPLY_US_LA_TRACKING_'.$o->id,
            ]
        )->assertOk()
          ->assertJsonPath('status', 'erp_shipment_updated')
          ->assertJsonPath('tracking_number', '9400READBACK')
          ->assertJsonPath('woo_sync', 'pending_vita');
        $this->assertSame('9400READBACK', $o->fresh()->shipment->tracking_number);
        Http::assertSentCount(2);
    }

    public function test_tracking_apply_rejects_wrong_confirmation_without_sending_or_writing(): void
    {
        $o = $this->order();
        $this->enable();
        config()->set('shipout.sandbox_tracking_apply_enabled', true);
        $this->actingAs($this->warehouseUser(['logistics.view','logistics.operate']));
        Http::fake();
        $this->postJson(
            "/logistics/oversea/us-moreplus/orders/{$o->id}/shipout/apply",
            ['tracking_number' => '9400READBACK', 'confirmation' => 'WRONG']
        )->assertStatus(422);
        Http::assertNothingSent();
        $this->assertNull($o->fresh()->shipment->tracking_number);
    }

    private function warehouseUser(array $permissions): User
    {
        $role = Role::query()->create([
            'key' => 'shipout-uat-'.uniqid(), 'label' => 'ShipOut UAT',
        ]);
        foreach ($permissions as $key) {
            $role->permissions()->firstOrCreate(['key' => $key], ['label' => $key]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }

    public function test_dede_moreplus_preflight_is_read_only_and_disabled_by_default(): void
    {
        $o = $this->order();
        config()->set('shipout.sandbox_read_enabled', false);
        config()->set('shipout.sandbox_dispatch_enabled', false);
        $this->actingAs($this->warehouseUser(['logistics.view', 'logistics.operate']));
        Http::fake();
        $this->get("/logistics/oversea/us-moreplus/orders/{$o->id}/shipout/preflight")
            ->assertOk()
            ->assertJsonPath('order_id', $o->id)
            ->assertJsonPath('ready', false)
            ->assertJsonPath('blockers.0', 'SANDBOX_API_READ_DISABLED')
            ->assertJsonPath('blockers.1', 'SANDBOX_DISPATCH_DISABLED');
        Http::assertNothingSent();
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
    }

    public function test_dede_moreplus_status_disabled_is_read_only_and_has_no_api_side_effect(): void
    {
        $o = $this->order();
        config()->set('shipout.sandbox_read_enabled', false);
        $this->actingAs($this->warehouseUser(['logistics.view']));
        Http::fake();
        $this->postJson("/logistics/oversea/us-moreplus/orders/{$o->id}/shipout/status", [])
            ->assertOk()
            ->assertJsonPath('state', 'disabled')
            ->assertJsonPath('read_only', true);
        Http::assertNothingSent();
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
    }

    public function test_user_without_execute_permission_cannot_submit_moreplus_order(): void
    {
        $o = $this->order();
        $this->actingAs($this->warehouseUser(['logistics.view']));
        Http::fake();
        $this->postJson("/logistics/oversea/us-moreplus/orders/{$o->id}/shipout/send", [
            'ship_date' => '2026-10-30',
            'payload_hash' => str_repeat('a', 64),
            'confirmation' => 'SUBMIT_US_LA_ORDER_'.$o->id,
        ])->assertForbidden();
        Http::assertNothingSent();
        $this->assertDatabaseCount('shipout_dispatch_attempts', 0);
    }

    public function test_non_moreplus_order_cannot_access_moreplus_shipout_action(): void
    {
        $o = $this->order();
        $other = Warehouse::query()->where('code', 'US-SM')->firstOrFail();
        $o->shipment->update(['warehouse_id' => $other->id]);
        $this->actingAs($this->warehouseUser(['logistics.view']));
        Http::fake();
        $this->postJson("/logistics/oversea/us-moreplus/orders/{$o->id}/shipout/status", [])
            ->assertNotFound();
        Http::assertNothingSent();
    }

}
