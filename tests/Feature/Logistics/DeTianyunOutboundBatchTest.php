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
            'summary' => ['v2' => true, 'de_authoritative_physical_v1' => true],
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
