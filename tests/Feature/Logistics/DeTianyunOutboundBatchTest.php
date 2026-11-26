<?php

namespace Tests\Feature\Logistics;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Application\LogisticsExecutionAccess;
use App\Modules\Logistics\Application\Xlwms\DeTianyunOutboundBatchService;
use App\Modules\Logistics\Application\Xlwms\DeTianyunLiveOrderCreateService;
use App\Modules\Logistics\Experience\Jobs\ProcessDeTianyunOutboundBatch;
use App\Modules\Logistics\Infrastructure\Models\Carrier;
use App\Modules\Logistics\Infrastructure\Models\CarrierService;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsInventorySnapshot;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class DeTianyunOutboundBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('xlwms.de_verified_channel_codes', ['DHL PAKET DOMESTIC']);
        Http::preventStrayRequests();
        $this->withoutVite();
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_production_batch_preflight_is_readable_but_real_create_stays_disabled_by_default(): void
    {
        $operator = $this->operator();
        $order = $this->order($operator, 'PROD-READ');
        $this->actingAs($operator);
        $beforeEnv = $this->app['env'];
        $this->app->instance('env', 'production');
        config()->set('xlwms.de_order_batch_read_enabled', true);
        config()->set('xlwms.de_order_batch_live_enabled', false);
        try {
            $this->get('/logistics/oversea/de-tianyun?view=pending')->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('deTianyunBatchUi', true)
                    ->where('deTianyunBatchLiveEnabled', false)
                    ->where('deTianyunBatchSimulation', false));
            $this->getJson('/logistics/oversea/de-tianyun/xlwms/batch/preview?scope=selected&order_ids='.$order->id)
                ->assertOk()->assertJsonPath('count', 0);
            $this->postJson('/logistics/oversea/de-tianyun/xlwms/batch/start', [
                'scope' => 'selected', 'order_ids' => (string) $order->id,
                'fingerprint' => str_repeat('a', 64), 'confirmation' => 'CONFIRM_DE_TIANYUN_BATCH_1',
            ])->assertForbidden();
            $this->assertDatabaseCount('xlwms_de_dispatch_batches', 0);
            Http::assertNothingSent();
        } finally {
            $this->app->instance('env', $beforeEnv);
        }
    }

    public function test_one_explicitly_approved_production_order_creates_one_oms_record_and_never_retries(): void
    {
        $operator = $this->operator();
        $order = $this->order($operator, 'PROD-LIVE');
        $this->actingAs($operator);
        $productId = (int) $order->items()->firstOrFail()->product_id;
        $warehouseId = (int) $order->shipment->warehouse_id;
        WarehouseInventory::query()->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->update(['provider_available_quantity' => 8]);
        $snapshot = XlwmsInventorySnapshot::query()->create([
            'warehouse_id' => $warehouseId, 'wh_code' => 'Hannover',
            'checksum' => str_repeat('a', 64), 'status' => 'blocked',
            'fetched_at' => now(), 'mapped_sku_count' => 1,
            'provider_sku_count' => 1, 'unmatched_sku_count' => 0,
            'blocked_sku_count' => 0,
            'summary' => ['v2' => true, 'de_authoritative_physical_v1' => true,
                'de_physical_applied_product_ids' => [$productId], 'de_authoritative_physical_sku_count' => 1],
        ]);
        $snapshot->rows()->create([
            'provider_sku' => 'DE-EXISTING', 'product_id' => $productId,
            'resolution_status' => 'matched', 'oms_total' => 8,
            'oms_available' => 8, 'oms_locked' => 0, 'oms_in_transit' => 0,
        ]);
        $oldEnv = $this->app['env'];
        $this->app->instance('env', 'production');
        config()->set('xlwms.de_order_batch_read_enabled', true);
        config()->set('xlwms.de_order_batch_live_enabled', true);
        config()->set('xlwms.de_order_batch_live_order_ids', [$order->id]);
        Queue::fake();
        $createCalls = 0;
        Http::fake(function ($request) use (&$createCalls, $order) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/outboundOrder/detail')) {
                return Http::response(['code' => 200, 'data' => []]);
            }
            if (str_ends_with($path, '/outboundOrder/pageList')) {
                if ($createCalls === 0) {
                    return Http::response(['code' => 200,
                        'data' => ['records' => [], 'total' => 0]]);
                }
                return Http::response(['code' => 200, 'data' => [
                    'records' => [[
                        'outboundOrderNo' => 'DE-LIVE-0001', 'whCode' => 'Hannover',
                        'thirdOrderNo' => 'BYL-ERP-'.$order->id,
                        'platformOrderNo' => (string) $order->erp_order_id,
                        'referOrderNo' => (string) $order->erp_order_id, 'status' => 0,
                    ]], 'total' => 1,
                ]]);
            }
            if (str_ends_with($path, '/logistics/channel/list')) {
                return Http::response(['code' => 200, 'data' => [
                    ['channelCode' => 'DHL PAKET DOMESTIC', 'whCodes' => ['Hannover']],
                ]]);
            }
            if (str_ends_with($path, '/product/pagelist')) {
                return Http::response(['code' => 200, 'data' => [
                    'records' => [['sku' => 'DE-EXISTING', 'approveStatus' => 2]],
                ]]);
            }
            if (str_ends_with($path, '/outboundOrder/create')) {
                $createCalls++;
                return Http::response(['code' => 200, 'data' => [[
                    'thirdOrderNo' => 'BYL-ERP-'.$order->id,
                    'success' => true, 'orderNo' => 'DE-LIVE-0001',
                ]]]);
            }
            return Http::response([], 500);
        });
        try {
            $preview = $this->getJson('/logistics/oversea/de-tianyun/xlwms/batch/preview?scope=selected&order_ids='.$order->id)
                ->assertOk()->assertJsonPath('count', 1)->json();
            $reply = $this->postJson('/logistics/oversea/de-tianyun/xlwms/batch/start', [
                'scope' => 'selected', 'order_ids' => (string) $order->id,
                'fingerprint' => $preview['fingerprint'],
                'confirmation' => 'CONFIRM_DE_TIANYUN_BATCH_1',
            ])->assertStatus(202)->assertJsonPath('dry_run', false);
            $batch = $reply->json('batch_id');
            Queue::assertPushed(ProcessDeTianyunOutboundBatch::class, 1);
            $job = new ProcessDeTianyunOutboundBatch($batch);
            $job->handle(app(DeTianyunOutboundBatchService::class),
                app(LogisticsExecutionAccess::class), app(DeTianyunLiveOrderCreateService::class));
            $this->getJson('/logistics/oversea/de-tianyun/xlwms/batch/'.$batch.'/status')
                ->assertOk()->assertJsonPath('items.0.status', 'created');
            self::assertSame(1, $createCalls);
            self::assertSame(1, DB::table('xlwms_dispatch_attempts')
                ->where('order_id', $order->id)->count());
            self::assertSame('oms_create_confirmed', DB::table('xlwms_dispatch_attempts')
                ->where('order_id', $order->id)->value('state'));
            self::assertSame('pending', $order->fresh()->shipment->shipment_status);
            $job->handle(app(DeTianyunOutboundBatchService::class),
                app(LogisticsExecutionAccess::class), app(DeTianyunLiveOrderCreateService::class));
            self::assertSame(1, $createCalls);
        } finally {
            $this->app->instance('env', $oldEnv);
        }
    }

    public function test_operator_mode_full_mocked_live_create_with_fresh_oms_stock_without_allowlist(): void
    {
        $operator = $this->operator();
        $order = $this->order($operator, 'OP-LIVE');
        $this->actingAs($operator);
        $productId = (int) $order->items()->firstOrFail()->product_id;
        $warehouseId = (int) $order->shipment->warehouse_id;
        WarehouseInventory::query()->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->update(['provider_available_quantity' => 8]);
        $snapshot = XlwmsInventorySnapshot::query()->create([
            'warehouse_id' => $warehouseId, 'wh_code' => 'Hannover',
            'checksum' => str_repeat('a', 64), 'status' => 'blocked',
            'fetched_at' => now(), 'mapped_sku_count' => 1,
            'provider_sku_count' => 1, 'unmatched_sku_count' => 0,
            'blocked_sku_count' => 0,
            'summary' => ['v2' => true, 'de_authoritative_physical_v1' => true,
                'de_physical_applied_product_ids' => [$productId], 'de_authoritative_physical_sku_count' => 1],
        ]);
        $snapshot->rows()->create([
            'provider_sku' => 'DE-EXISTING', 'product_id' => $productId,
            'resolution_status' => 'matched', 'oms_total' => 8,
            'oms_available' => 8, 'oms_locked' => 0, 'oms_in_transit' => 0,
        ]);
        $oldEnv = $this->app['env'];
        $this->app->instance('env', 'production');
        config()->set('xlwms.de_order_batch_read_enabled', true);
        config()->set('xlwms.de_order_batch_live_enabled', true);
        config()->set('xlwms.de_order_batch_live_order_ids', []);
        config()->set('xlwms.de_operator_batch_enabled', true);
        config()->set('xlwms.de_production_read_enabled', true);
        config()->set('xlwms.de_authoritative_auto_apply_enabled', true);
        config()->set('xlwms.lot_reconciliation_v2_enabled', true);
        config()->set('inventory_sku_gate.enabled', true);
        $inventory = \Mockery::mock(\App\Modules\Logistics\Application\Xlwms\XlwmsInventoryGateway::class);
        $inventory->shouldReceive('productPage')->andReturn(['code'=>200,'data'=>[
            'records'=>[['sku'=>'DE-EXISTING','productName'=>'Synthetic'], ['sku'=>'UNKNOWN-ZERO'], ['sku'=>'UNKNOWN-POSITIVE']],
            'total'=>3,'page'=>1,'pageSize'=>10,'pages'=>1,
        ]]);
        $inventory->shouldReceive('integratedInventoryPage')->andReturn(['code'=>200,'data'=>[
            'records'=>[['sku'=>'DE-EXISTING','whCode'=>'Hannover','stockType'=>0,'productTotalAmount'=>8,
                'productStockDtl'=>['availableAmount'=>8,'lockAmount'=>0,'transportAmount'=>0]],
                ['sku'=>'UNKNOWN-ZERO','whCode'=>'Hannover','stockType'=>0,'productTotalAmount'=>0,
                    'productStockDtl'=>['availableAmount'=>0,'lockAmount'=>0,'transportAmount'=>0]],
                ['sku'=>'UNKNOWN-POSITIVE','whCode'=>'Hannover','stockType'=>0,'productTotalAmount'=>3,
                    'productStockDtl'=>['availableAmount'=>3,'lockAmount'=>0,'transportAmount'=>0]]],
            'total'=>3,'page'=>1,'pageSize'=>10,'pages'=>1,
        ]]);
        $this->app->instance(\App\Modules\Logistics\Application\Xlwms\XlwmsInventoryGateway::class, $inventory);
        Queue::fake();
        $createCalls = 0;
        Http::fake(function ($request) use (&$createCalls, $order) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/outboundOrder/detail')) {
                return Http::response(['code' => 200, 'data' => []]);
            }
            if (str_ends_with($path, '/outboundOrder/pageList')) {
                if ($createCalls === 0) {
                    return Http::response(['code' => 200,
                        'data' => ['records' => [], 'total' => 0]]);
                }
                return Http::response(['code' => 200, 'data' => [
                    'records' => [[
                        'outboundOrderNo' => 'DE-LIVE-0001', 'whCode' => 'Hannover',
                        'thirdOrderNo' => 'BYL-ERP-'.$order->id,
                        'platformOrderNo' => (string) $order->erp_order_id,
                        'referOrderNo' => (string) $order->erp_order_id, 'status' => 0,
                    ]], 'total' => 1,
                ]]);
            }
            if (str_ends_with($path, '/logistics/channel/list')) {
                return Http::response(['code' => 200, 'data' => [
                    ['channelCode' => 'DHL PAKET DOMESTIC', 'whCodes' => ['Hannover']],
                ]]);
            }
            if (str_ends_with($path, '/product/pagelist')) {
                return Http::response(['code' => 200, 'data' => [
                    'records' => [['sku' => 'DE-EXISTING', 'approveStatus' => 2]],
                ]]);
            }
            if (str_ends_with($path, '/outboundOrder/create')) {
                $createCalls++;
                return Http::response(['code' => 200, 'data' => [[
                    'thirdOrderNo' => 'BYL-ERP-'.$order->id,
                    'success' => true, 'orderNo' => 'DE-LIVE-0001',
                ]]]);
            }
            return Http::response([], 500);
        });
        try {
            $preview = $this->getJson('/logistics/oversea/de-tianyun/xlwms/batch/preview?scope=selected&order_ids='.$order->id)
                ->assertOk()->assertJsonPath('count', 1)->json();
            $reply = $this->postJson('/logistics/oversea/de-tianyun/xlwms/batch/start', [
                'scope' => 'selected', 'order_ids' => (string) $order->id,
                'fingerprint' => $preview['fingerprint'],
                'confirmation' => 'CONFIRM_DE_TIANYUN_BATCH_1',
            ])->assertStatus(202)->assertJsonPath('dry_run', false);
            $batch = $reply->json('batch_id');
            Queue::assertNothingPushed();
            $job = new ProcessDeTianyunOutboundBatch($batch);
            $job->handle(app(DeTianyunOutboundBatchService::class),
                app(LogisticsExecutionAccess::class), app(DeTianyunLiveOrderCreateService::class));
            $this->getJson('/logistics/oversea/de-tianyun/xlwms/batch/'.$batch.'/status')
                ->assertOk()->assertJsonPath('items.0.status', 'created');
            self::assertSame(1, $createCalls);
            self::assertSame(1, DB::table('xlwms_dispatch_attempts')
                ->where('order_id', $order->id)->count());
            self::assertSame('oms_create_confirmed', DB::table('xlwms_dispatch_attempts')
                ->where('order_id', $order->id)->value('state'));
            self::assertSame('pending', $order->fresh()->shipment->shipment_status);
            $job->handle(app(DeTianyunOutboundBatchService::class),
                app(LogisticsExecutionAccess::class), app(DeTianyunLiveOrderCreateService::class));
            self::assertSame(1, $createCalls);
        } finally {
            $this->app->instance('env', $oldEnv);
        }
    }

    public function test_de_preflight_rejects_only_unproven_or_insufficient_order_items_and_stale_source(): void
    {
        config()->set('inventory_sku_gate.enabled', true);
        config()->set('xlwms.de_authoritative_auto_apply_enabled', true);
        $operator = $this->operator();
        $good = $this->order($operator, 'SKU-ISOLATED-GOOD');
        $bad = $this->order($operator, 'SKU-ISOLATED-BAD', 'DE', true, 'DE-NOT-PROVEN');
        $mixed = $this->order($operator, 'SKU-ISOLATED-MIXED');
        $tooMany = $this->order($operator, 'SKU-ISOLATED-QUANTITY');
        $goodId = (int) $good->items()->firstOrFail()->product_id;
        $badId = (int) $bad->items()->firstOrFail()->product_id;
        $warehouseId = (int) $good->shipment->warehouse_id;
        $mixed->items()->create(['product_id' => $badId, 'sku' => 'DE-NOT-PROVEN',
            'product_summary' => 'Synthetic', 'quantity' => 1, 'unit_price' => 10, 'line_revenue' => 10]);
        $tooMany->items()->firstOrFail()->update(['quantity' => 5]);
        $tooMany->items()->create(['product_id' => $goodId, 'sku' => 'DE-EXISTING',
            'product_summary' => 'Synthetic', 'quantity' => 5, 'unit_price' => 10, 'line_revenue' => 50]);
        $snapshot = XlwmsInventorySnapshot::query()->create([
            'warehouse_id' => $warehouseId, 'wh_code' => 'Hannover', 'checksum' => str_repeat('c', 64),
            'status' => 'blocked', 'fetched_at' => now(), 'mapped_sku_count' => 1,
            'provider_sku_count' => 2, 'unmatched_sku_count' => 1, 'blocked_sku_count' => 1,
            'summary' => ['v2' => true],
        ]);
        $snapshot->rows()->create(['provider_sku' => 'DE-EXISTING', 'product_id' => $goodId,
            'resolution_status' => 'matched', 'oms_total' => 8, 'oms_available' => 8, 'oms_locked' => 0, 'oms_in_transit' => 0]);
        $snapshot->rows()->create(['provider_sku' => 'UNKNOWN-SKU', 'product_id' => null,
            'resolution_status' => 'unmatched', 'oms_total' => 5, 'oms_available' => 5, 'oms_locked' => 0, 'oms_in_transit' => 0]);
        app(\App\Modules\Logistics\Application\Xlwms\DeAuthoritativePhysicalStockSyncService::class)
            ->reconcileSnapshot($snapshot->id, true);
        $preview = app(\App\Modules\Logistics\Application\Xlwms\SingleOrderPreview::class);
        self::assertSame([], $preview->forOrder($good, true, false, 'DE')['blockers']);
        foreach ([$bad, $mixed, $tooMany] as $order) {
            self::assertContains('OMS_SNAPSHOT_STOCK_INSUFFICIENT', $preview->forOrder($order, true, false, 'DE')['blockers']);
        }
        $snapshot->forceFill(['fetched_at' => now()->subMinutes(3)])->save();
        self::assertContains('OMS_SNAPSHOT_NOT_FRESH', $preview->forOrder($good, true, false, 'DE')['blockers']);
        Http::assertNothingSent();
    }

    public function test_operator_confirmed_batch_uses_persistent_exact_claim_not_global_order_whitelist(): void
    {
        $operator = $this->operator();
        $first = $this->order($operator, 'OP-FIRST');
        $other = $this->order($operator, 'OP-OTHER');
        $this->actingAs($operator);
        Queue::fake();
        $oldEnv = $this->app['env'];
        $this->app->instance('env', 'production');
        config()->set('xlwms.de_order_batch_read_enabled', true);
        config()->set('xlwms.de_order_batch_live_enabled', true);
        config()->set('xlwms.de_operator_batch_enabled', true);
        config()->set('xlwms.de_order_batch_live_order_ids', []);
        try {
            $this->get('/logistics/oversea/de-tianyun?view=pending')->assertOk()
                ->assertInertia(fn ($page) => $page->where('deTianyunBatchLiveEnabled', true));
            $preview = $this->getJson('/logistics/oversea/de-tianyun/xlwms/batch/preview?scope=selected&order_ids='.$first->id)
                ->assertOk()->assertJsonPath('count', 1)->json();
            $result = $this->postJson('/logistics/oversea/de-tianyun/xlwms/batch/start', [
                'scope' => 'selected', 'order_ids' => (string) $first->id,
                'fingerprint' => $preview['fingerprint'], 'confirmation' => 'CONFIRM_DE_TIANYUN_BATCH_1',
            ])->assertStatus(202)->json();
            Queue::assertNothingPushed();
            $auth = \App\Modules\Logistics\Application\Xlwms\DeTianyunDispatchAuthorization::class;
            self::assertFalse($auth::permits($first->id));
            DB::table('xlwms_de_dispatch_batches')->where('id', $result['batch_id'])->update(['status' => 'running']);
            DB::table('xlwms_de_dispatch_batch_items')->where('batch_id', $result['batch_id'])->update(['status' => 'processing']);
            $hash = $preview['items'][0]['payload_hash'];
            self::assertTrue($auth::permits($first->id, $operator->id, $hash));
            self::assertFalse($auth::permits($other->id));
            self::assertFalse($auth::permits($first->id, $operator->id, str_repeat('b',64)));
            $payload = app(\App\Modules\Logistics\Application\Xlwms\SingleOrderPreview::class)->forOrder($first, false, false, 'DE')['payload'];
            self::assertFalse($auth::permitsCreate($first->id, $first->shipment->warehouse_id, $payload));
            \App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt::query()->create([
                'order_id'=>$first->id, 'warehouse_id'=>$first->shipment->warehouse_id,
                'third_order_no'=>'BYL-ERP-'.$first->id, 'request_fingerprint'=>$hash,
                'requested_by'=>$operator->id, 'state'=>'request_in_flight',
            ]);
            self::assertTrue($auth::permitsCreate($first->id, $first->shipment->warehouse_id, $payload));
            self::assertFalse($auth::permitsCreate($first->id, $first->shipment->warehouse_id, array_replace($payload, ['postCode'=>'12345'])));
            Http::assertNothingSent();
        } finally {
            $this->app->instance('env', $oldEnv);
        }
    }

    public function test_de_readback_tracking_moves_confirmed_order_to_issued_without_shipped_or_woo_write(): void
    {
        $operator = $this->operator();
        $order = $this->order($operator, 'TRACKING');
        \App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt::query()->create([
            'order_id'=>$order->id, 'warehouse_id'=>$order->shipment->warehouse_id,
            'third_order_no'=>'BYL-ERP-'.$order->id, 'request_fingerprint'=>str_repeat('a',64),
            'requested_by'=>$operator->id, 'state'=>'oms_create_confirmed',
            'oms_order_no'=>'DE-TRACK-0001', 'oms_status_code'=>'2',
        ]);
        Http::fake(['https://api.xlwms.com/openapi/v1/outboundOrder/pageList*' => Http::response(['code'=>200,'data'=>[
            'total'=>1,'records'=>[['outboundOrderNo'=>'DE-TRACK-0001','whCode'=>'Hannover',
                'thirdOrderNo'=>'BYL-ERP-'.$order->id,'referOrderNo'=>$order->erp_order_id,
                'platformOrderNo'=>$order->erp_order_id,'status'=>2,'logisticsTrackNo'=>'CF123456789DE']],
        ]])]);
        $r = app(\App\Modules\Logistics\Application\Xlwms\DeTianyunStatusRefresh::class)->run();
        self::assertSame(['checked'=>1,'refreshed'=>1,'failed'=>0,'tracking_staged'=>1],$r);
        $this->assertDatabaseHas('xlwms_dispatch_attempts',['order_id'=>$order->id,'oms_tracking_no'=>'CF123456789DE']);
        $this->assertDatabaseHas('warehouse_dispatch_states',['order_id'=>$order->id,'status'=>'oms_processing']);
        $this->actingAs($operator)->get('/logistics/oversea/de-tianyun?view=pending')->assertOk()
            ->assertInertia(fn($p)=>$p->where('rows.total',0));
        $this->get('/logistics/oversea/de-tianyun?view=issued')->assertOk()
            ->assertInertia(fn($p)=>$p->where('rows.total',1)->where('rows.data.0.order_id',$order->id)
                ->where('rows.data.0.xlwms.oms_tracking_no','CF123456789DE')
                ->where('rows.data.0.warehouse_dispatch.status','oms_processing'));
        self::assertSame('pending',$order->fresh()->shipment->shipment_status);
        self::assertSame('CF123456789DE',$order->fresh()->shipment->tracking_number);
        $repeat = app(\App\Modules\Logistics\Application\Xlwms\DeTianyunStatusRefresh::class)->run();
        self::assertSame(0,$repeat['tracking_staged']);
        Http::assertSentCount(2);
    }

    private function operator(): User
    {
        $user = User::factory()->create(['is_active' => true, 'permission_mode' => 'role']);
        $role = Role::query()->firstOrCreate(['key' => 'role_preset_administrator'], ['label' => '管理员']);
        foreach (['logistics.view', 'logistics.operate'] as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }

    private function order(User $operator, string $suffix, string $warehouseCode = 'DE',
        bool $reviewed = true, string $sku = 'DE-EXISTING'): Order
    {
        $warehouse = Warehouse::query()->firstOrCreate(['code' => $warehouseCode], [
            'slug' => strtolower($warehouseCode), 'name' => $warehouseCode === 'DE' ? '德国天运' : 'US MorePlus',
            'type' => 'warehouse', 'is_active' => true,
        ]);
        $carrier = Carrier::query()->firstOrCreate(['name' => 'DHL'], ['is_active' => true]);
        $service = CarrierService::query()->firstOrCreate(
            ['carrier_id' => $carrier->id, 'service_key' => 'paket_domestic'],
            ['service_value' => 'DHL PAKET DOMESTIC', 'is_active' => true],
        );
        $product = Product::query()->firstOrCreate(['sku' => $sku], [
            'name' => $sku, 'product_type' => 'simple', 'is_active' => true,
        ]);
        WarehouseInventory::query()->updateOrCreate([
            'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
        ], ['quantity_on_hand' => 8, 'quantity_reserved' => 0]);
        $order = Order::query()->create([
            'internal_order_number' => 'DE-BATCH-'.$suffix,
            'erp_order_id' => '007-1121-DE-'.$suffix,
            'source_type' => 'manual_other', 'order_kind' => 'customer',
            'order_date' => '2026-11-21', 'order_status' => 'pending_shipment',
        ]);
        $order->address()->create([
            'recipient_name' => 'Dev Synthetic', 'country' => 'DE',
            'province_state' => 'NI', 'city' => 'Hannover',
            'address_line_1' => '100 Simulation Street', 'postal_code' => '30159',
        ]);
        $order->items()->create([
            'product_id' => $product->id, 'product_summary' => 'Synthetic',
            'sku' => $sku, 'quantity' => 1, 'unit_price' => 10, 'line_revenue' => 10,
        ]);
        $shipment = $order->shipment()->create([
            'warehouse_id' => $warehouse->id, 'shipment_status' => 'pending',
            'warehouse_selection_source' => 'manual',
            'carrier' => 'DHL', 'service' => 'DHL PAKET DOMESTIC',
            'carrier_service_id' => $service->id,
        ]);
        if ($reviewed) {
            $shipment->overseaReview()->create([
                'status' => 'approved', 'reviewed_warehouse_id' => $warehouse->id,
                'reviewed_by' => $operator->id, 'reviewed_at' => now(),
            ]);
        }
        WarehouseOpenApiConnection::query()->updateOrCreate([
            'warehouse_id' => $warehouse->id, 'provider' => 'xlwms',
        ], [
            'app_key_encrypted' => 'TEST-APPKEY-NOT-REAL',
            'app_secret_encrypted' => 'TEST-SECRET-NOT-REAL',
            'oms_warehouse_code' => $warehouseCode === 'DE' ? 'Hannover' : 'US-LA',
        ]);

        return $order->fresh();
    }

    public function test_selected_two_orders_simulate_in_order_without_any_provider_io_or_erp_ship(): void
    {
        $operator = $this->operator();
        $first = $this->order($operator, '01');
        $second = $this->order($operator, '02');
        $other = $this->order($operator, '03');
        $this->actingAs($operator);

        $preview = $this->getJson('/logistics/oversea/de-tianyun/xlwms/batch/preview?scope=selected&order_ids='.$first->id.','.$second->id)
            ->assertOk()->assertJsonPath('count', 2)
            ->assertJsonPath('excluded_count', 0)
            ->assertJsonPath('items.0.ready', true)->json();
        $start = $this->postJson('/logistics/oversea/de-tianyun/xlwms/batch/start', [
            'scope' => 'selected', 'order_ids' => $first->id.','.$second->id,
            'fingerprint' => $preview['fingerprint'],
            'confirmation' => 'CONFIRM_DE_TIANYUN_BATCH_2',
        ])->assertStatus(202)->assertJsonPath('dry_run', true)
            ->assertJsonPath('provider_io', false)->assertJsonPath('total', 2);
        $this->getJson('/logistics/oversea/de-tianyun/xlwms/batch/'.$start->json('batch_id').'/status')
            ->assertOk()->assertJsonPath('status', 'completed')
            ->assertJsonPath('processed', 2)
            ->assertJsonPath('items.0.status', 'simulated')
            ->assertJsonPath('items.1.status', 'simulated');
        $this->assertDatabaseMissing('xlwms_de_dispatch_batch_items', ['order_id' => $other->id]);
        $this->assertDatabaseCount('xlwms_dispatch_attempts', 0);
        $this->assertSame('pending', $first->fresh()->shipment->shipment_status);
        $this->assertSame('pending', $second->fresh()->shipment->shipment_status);
        Http::assertNothingSent();
    }

    public function test_single_order_and_blocked_selection_share_same_preview_contract(): void
    {
        $operator = $this->operator();
        $ready = $this->order($operator, '11');
        $wrong = $this->order($operator, '12', 'US-LA');
        $unreviewed = $this->order($operator, '13', 'DE', false);
        $this->actingAs($operator);

        $preview = $this->getJson('/logistics/oversea/de-tianyun/xlwms/batch/preview?scope=selected&order_ids='.$ready->id.','.$wrong->id.','.$unreviewed->id)
            ->assertOk()->assertJsonPath('count', 1)
            ->assertJsonPath('excluded_count', 2)
            ->assertJsonPath('items.0.ready', true)->json();
        self::assertFalse($preview['items'][1]['ready']);
        self::assertFalse($preview['items'][2]['ready']);
        $single = $this->getJson('/logistics/oversea/de-tianyun/xlwms/batch/preview?scope=selected&order_ids='.$ready->id)
            ->assertOk()->assertJsonPath('count', 1)->json();
        $start = $this->postJson('/logistics/oversea/de-tianyun/xlwms/batch/start', [
            'scope' => 'selected', 'order_ids' => (string) $ready->id,
            'fingerprint' => $single['fingerprint'], 'confirmation' => 'CONFIRM_DE_TIANYUN_BATCH_1',
        ])->assertStatus(202);
        $this->assertDatabaseHas('xlwms_de_dispatch_batch_items', [
            'batch_id' => $start->json('batch_id'), 'order_id' => $ready->id,
            'status' => 'simulated',
        ]);
        $this->assertDatabaseCount('xlwms_de_dispatch_batch_items', 1);
        Http::assertNothingSent();
    }

    public function test_stale_fingerprint_and_all_scope_cannot_create_a_batch(): void
    {
        $operator = $this->operator();
        $order = $this->order($operator, '21');
        $this->actingAs($operator);
        $this->getJson('/logistics/oversea/de-tianyun/xlwms/batch/preview?scope=all')
            ->assertStatus(422)->assertJsonPath('error_code', 'DE_BATCH_SELECTION_INVALID');
        $this->postJson('/logistics/oversea/de-tianyun/xlwms/batch/start', [
            'scope' => 'selected', 'order_ids' => (string) $order->id,
            'fingerprint' => str_repeat('a', 64), 'confirmation' => 'CONFIRM_DE_TIANYUN_BATCH_1',
        ])->assertStatus(409)->assertJsonPath('error_code', 'DE_BATCH_STALE');
        $this->assertDatabaseCount('xlwms_de_dispatch_batches', 0);
        Http::assertNothingSent();
    }

    public function test_de_dhl_international_maps_native_code_without_changing_erp_service_or_bypassing_gate(): void
    {
        $operator = $this->operator();
        $order = $this->order($operator, 'DHL-INTERNATIONAL');
        $service = CarrierService::query()->firstOrCreate(
            ['carrier_id' => $order->shipment->carrierService->carrier_id, 'service_key' => 'paket_international'],
            ['service_value' => 'DHL PAKET International', 'is_active' => true],
        );
        $order->shipment()->update(['carrier_service_id' => $service->id, 'service' => 'DHL PAKET International']);
        $order->address()->update(['country' => 'SK']);
        $preview = app(\App\Modules\Logistics\Application\Xlwms\SingleOrderPreview::class);
        $blocked = $preview->forOrder($order->fresh(), false, false, 'DE');
        self::assertSame('DHL PAKET International N', $blocked['payload']['logisticsChannel']);
        self::assertContains('DE_OMS_CHANNEL_NOT_VERIFIED', $blocked['blockers']);
        config()->set('xlwms.de_verified_channel_codes', ['DHL PAKET DOMESTIC', 'DHL PAKET International N']);
        $ready = $preview->forOrder($order->fresh(), false, false, 'DE');
        self::assertSame('DHL PAKET International N', $ready['payload']['logisticsChannel']);
        self::assertNotContains('DE_OMS_CHANNEL_NOT_VERIFIED', $ready['blockers']);
        self::assertSame('DHL PAKET International', $order->fresh()->shipment->service);
        self::assertSame('DHL PAKET International', $service->fresh()->service_value);
        $other = $this->order($operator, 'DHL-NON-DE', 'US-MOREPLUS');
        $other->shipment()->update(['carrier_service_id' => $service->id, 'service' => 'DHL PAKET International']);
        $otherPreview = $preview->forOrder($other->fresh(), false, false, 'US-MOREPLUS');
        self::assertSame('DHL PAKET International', $otherPreview['payload']['logisticsChannel']);
        Http::assertNothingSent();
    }

    public function test_stale_address_and_unverified_channel_are_fail_closed(): void
    {
        $operator = $this->operator();
        $order = $this->order($operator, '41');
        $this->actingAs($operator);
        $preview = $this->getJson('/logistics/oversea/de-tianyun/xlwms/batch/preview?scope=selected&order_ids='.$order->id)
            ->assertOk()->assertJsonPath('count', 1)->json();

        $order->address()->update(['address_line_1' => '200 Changed Simulation Street']);
        $this->postJson('/logistics/oversea/de-tianyun/xlwms/batch/start', [
            'scope' => 'selected', 'order_ids' => (string) $order->id,
            'fingerprint' => $preview['fingerprint'],
            'confirmation' => 'CONFIRM_DE_TIANYUN_BATCH_1',
        ])->assertStatus(409)->assertJsonPath('error_code', 'DE_BATCH_STALE');

        config()->set('xlwms.de_verified_channel_codes', []);
        $this->getJson('/logistics/oversea/de-tianyun/xlwms/batch/preview?scope=selected&order_ids='.$order->id)
            ->assertOk()->assertJsonPath('count', 0)
            ->assertJsonFragment(['DE_OMS_CHANNEL_NOT_VERIFIED']);
        $this->assertDatabaseCount('xlwms_de_dispatch_batches', 0);
        Http::assertNothingSent();
    }

    public function test_worker_rechecks_selected_order_and_never_simulates_stale_payload(): void
    {
        $operator = $this->operator();
        $order = $this->order($operator, '51');
        $this->actingAs($operator);
        Queue::fake();
        $preview = $this->getJson('/logistics/oversea/de-tianyun/xlwms/batch/preview?scope=selected&order_ids='.$order->id)
            ->assertOk()->assertJsonPath('count', 1)->json();
        $start = $this->postJson('/logistics/oversea/de-tianyun/xlwms/batch/start', [
            'scope' => 'selected', 'order_ids' => (string) $order->id,
            'fingerprint' => $preview['fingerprint'],
            'confirmation' => 'CONFIRM_DE_TIANYUN_BATCH_1',
        ])->assertStatus(202);
        Queue::assertPushed(ProcessDeTianyunOutboundBatch::class, 1);
        $order->address()->update(['city' => 'Changed Dev City']);

        $job = new ProcessDeTianyunOutboundBatch($start->json('batch_id'));
        $job->handle(app(DeTianyunOutboundBatchService::class), app(LogisticsExecutionAccess::class));
        $this->getJson('/logistics/oversea/de-tianyun/xlwms/batch/'.$start->json('batch_id').'/status')
            ->assertOk()->assertJsonPath('status', 'completed_partial')
            ->assertJsonPath('items.0.status', 'skipped')
            ->assertJsonPath('items.0.result_code', 'DE_PREFLIGHT_CHANGED');
        $this->assertSame('pending', $order->fresh()->shipment->shipment_status);
        Http::assertNothingSent();
    }

    public function test_de_ui_prop_matches_execution_authority_and_is_never_on_in_production(): void
    {
        $operator = $this->operator();
        $this->actingAs($operator);
        $this->get('/logistics/oversea/de-tianyun?view=pending')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Logistics/Oversea/Show')
                ->where('deTianyunBatchUi', true));
        $this->get('/logistics/oversea/us-moreplus?view=pending')->assertOk()
            ->assertInertia(fn ($page) => $page->where('deTianyunBatchUi', false));

        $original = $this->app['env'];
        $this->app->instance('env', 'production');
        try {
            $this->get('/logistics/oversea/de-tianyun?view=pending')->assertOk()
                ->assertInertia(fn ($page) => $page->where('deTianyunBatchUi', false));
        } finally {
            $this->app->instance('env', $original);
        }
        Http::assertNothingSent();
    }

    public function test_unprivileged_and_production_cannot_start_even_a_simulation(): void
    {
        $operator = $this->operator();
        $order = $this->order($operator, '31');
        $unauthorized = User::factory()->create(['is_active' => true]);
        $this->actingAs($unauthorized);
        $this->getJson('/logistics/oversea/de-tianyun/xlwms/batch/preview?scope=selected&order_ids='.$order->id)
            ->assertForbidden();
        $this->postJson('/logistics/oversea/de-tianyun/xlwms/batch/start', [
            'scope' => 'selected', 'order_ids' => (string) $order->id,
            'fingerprint' => str_repeat('a', 64), 'confirmation' => 'CONFIRM_DE_TIANYUN_BATCH_1',
        ])->assertForbidden();
        $this->actingAs($operator);
        $original = $this->app['env'];
        $this->app->instance('env', 'production');
        try {
            $this->getJson('/logistics/oversea/de-tianyun/xlwms/batch/preview?scope=selected&order_ids='.$order->id)
                ->assertForbidden();
            $this->postJson('/logistics/oversea/de-tianyun/xlwms/batch/start', [
                'scope' => 'selected', 'order_ids' => (string) $order->id,
                'fingerprint' => str_repeat('a', 64), 'confirmation' => 'CONFIRM_DE_TIANYUN_BATCH_1',
            ])->assertForbidden();
        } finally {
            $this->app->instance('env', $original);
        }
        $this->assertDatabaseCount('xlwms_de_dispatch_batches', 0);
        Http::assertNothingSent();
    }
}
