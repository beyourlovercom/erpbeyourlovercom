<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Application\Xlwms\XlwmsInventoryGateway;
use App\Modules\Logistics\Application\Xlwms\XlwmsInventorySyncService;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Sync\Experience\Jobs\RunDeTianyunWarehouseSync;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class DeTianyunWarehouseManualSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('xlwms.de_manual_sync_enabled', true);
    }

    public function test_existing_warehouse_page_reports_real_snapshot_separately_from_erp_apply(): void
    {
        $warehouse = $this->warehouse();
        $this->connection($warehouse);
        DB::table('xlwms_inventory_snapshots')->insert([
            'warehouse_id' => $warehouse->id, 'wh_code' => 'Hannover',
            'checksum' => str_repeat('a', 64), 'status' => 'read_only',
            'fetched_at' => now(), 'provider_sku_count' => 34,
            'mapped_sku_count' => 0, 'unmatched_sku_count' => 34,
            'blocked_sku_count' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $actor = $this->actor(['sync.view', 'sync.operate']);

        $this->actingAs($actor)->get('/sync/warehouses')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sync/Warehouses')
                ->where('warehouses', fn ($rows): bool => $rows
                    ->contains(fn (array $row): bool => $row['code'] === 'DE'
                        && $row['connector_status'] === 'configured'
                        && $row['can_sync'] === true
                        && $row['last_sync_status'] === 'read_only'
                        && $row['last_applied_at'] === null
                        && $row['sync_counts']['provider'] === 34
                        && $row['sync_counts']['unmatched'] === 34))
            );
    }

    public function test_operator_starts_one_read_only_job_and_can_query_sanitized_status(): void
    {
        Queue::fake();
        $warehouse = $this->warehouse();
        $this->connection($warehouse);
        $actor = $this->actor(['sync.view', 'sync.operate']);
        $start = $this->actingAs($actor)
            ->postJson("/sync/warehouses/{$warehouse->id}/sync");
        $start->assertStatus(202)->assertJsonPath('status', 'queued')
            ->assertJsonPath('mode', 'read_only')->assertJsonPath('erp_stock_updated', false);
        $id = (int) $start->json('run_id');
        Queue::assertPushed(RunDeTianyunWarehouseSync::class, 1);
        self::assertSame(1, DB::table('xlwms_inventory_manual_runs')->count());
        self::assertSame(0, (int) DB::table('xlwms_inventory_manual_runs')
            ->where('id', $id)->value('apply_requested'));

        $this->getJson("/sync/warehouses/{$warehouse->id}/sync/{$id}")
            ->assertOk()->assertJsonPath('status', 'queued')
            ->assertJsonPath('erp_stock_updated', false)
            ->assertJsonMissingPath('app_secret_encrypted');

        $this->postJson("/sync/warehouses/{$warehouse->id}/sync")
            ->assertStatus(409)->assertJsonPath('error_code', 'XLWMS_DE_SYNC_ALREADY_RUNNING');
        Queue::assertPushed(RunDeTianyunWarehouseSync::class, 1);
    }

    public function test_sync_requires_operator_permission_and_explicit_dev_switch(): void
    {
        Queue::fake();
        $warehouse = $this->warehouse();
        $this->connection($warehouse);

        $viewer = $this->actor(['sync.view']);
        $this->actingAs($viewer)->postJson("/sync/warehouses/{$warehouse->id}/sync")->assertForbidden();
        $this->get('/sync/warehouses')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('warehouses',
                fn ($rows): bool => $rows
                    ->contains(fn (array $row): bool => $row['code'] === 'DE' && ! $row['can_sync'])));
        $operator = $this->actor(['sync.view', 'sync.operate']);
        config()->set('xlwms.de_manual_sync_enabled', false);
        $this->actingAs($operator)->postJson("/sync/warehouses/{$warehouse->id}/sync")->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_wrong_warehouse_and_production_cannot_start_de_sync(): void
    {
        Queue::fake();
        $warehouse = $this->warehouse();
        $this->connection($warehouse);
        $actor = $this->actor(['sync.view', 'sync.operate']);
        $other = Warehouse::query()->where('code', 'US-SM')->firstOrFail();
        $this->actingAs($actor)->postJson("/sync/warehouses/{$other->id}/sync")->assertNotFound();

        $this->withoutMiddleware(PreventRequestForgery::class);
        $this->app->detectEnvironment(static fn (): string => 'production');
        try {
            $this->postJson("/sync/warehouses/{$warehouse->id}/sync")->assertForbidden();
        } finally {
            $this->app->detectEnvironment(static fn (): string => 'testing');
        }
        Queue::assertNothingPushed();
    }

    public function test_missing_connector_returns_safe_error_and_no_job(): void
    {
        Queue::fake();
        $warehouse = $this->warehouse();
        $actor = $this->actor(['sync.view', 'sync.operate']);
        $this->actingAs($actor)->postJson("/sync/warehouses/{$warehouse->id}/sync")
            ->assertStatus(409)->assertJsonPath('error_code', 'XLWMS_DE_CONNECTOR_NOT_CONFIGURED');
        Queue::assertNothingPushed();
    }

    public function test_worker_reads_fake_oms_inventory_and_records_read_only_without_erp_write(): void
    {
        Queue::fake();
        $warehouse = $this->warehouse();
        $this->connection($warehouse);
        $actor = $this->actor(['sync.view', 'sync.operate']);
        $start = $this->actingAs($actor)->postJson("/sync/warehouses/{$warehouse->id}/sync")
            ->assertStatus(202);
        $id = (int) $start->json('run_id');

        $gateway = \Mockery::mock(XlwmsInventoryGateway::class);
        $gateway->shouldReceive('productPage')->once()->andReturn([
            'code' => 200, 'data' => ['records' => [['sku' => 'DE-UNKNOWN-TEST',
                'productName' => 'Synthetic test item']], 'total' => 1, 'page' => 1,
                'pageSize' => 10, 'pages' => 1], 'msg' => 'OK',
        ]);
        $gateway->shouldReceive('integratedInventoryPage')->once()->andReturn([
            'code' => 200, 'data' => ['records' => [['sku' => 'DE-UNKNOWN-TEST',
                'whCode' => 'Hannover', 'stockType' => 0, 'productTotalAmount' => 12,
                'productStockDtl' => ['availableAmount' => 12, 'lockAmount' => 0,
                    'transportAmount' => 0]]], 'total' => 1, 'page' => 1,
                'pageSize' => 10, 'pages' => 1], 'msg' => 'OK',
        ]);
        $this->app->instance(XlwmsInventoryGateway::class, $gateway);
        $this->app->call([new RunDeTianyunWarehouseSync($id), 'handle']);

        $this->getJson("/sync/warehouses/{$warehouse->id}/sync/{$id}")
            ->assertOk()->assertJsonPath('status', 'blocked')
            ->assertJsonPath('erp_stock_updated', false)
            ->assertJsonPath('provider_sku_count', 1)
            ->assertJsonPath('unmatched_sku_count', 1);
        self::assertSame(1, DB::table('xlwms_inventory_snapshots')->where('warehouse_id', $warehouse->id)->count());
        self::assertSame(0, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->count());
    }

    public function test_stale_queue_run_is_marked_failed_and_operator_can_retry(): void
    {
        Queue::fake();
        $warehouse = $this->warehouse();
        $this->connection($warehouse);
        $actor = $this->actor(['sync.view', 'sync.operate']);
        $first = $this->actingAs($actor)->postJson("/sync/warehouses/{$warehouse->id}/sync")
            ->assertStatus(202);
        $id = (int) $first->json('run_id');
        DB::table('xlwms_inventory_manual_runs')->where('id', $id)
            ->update(['updated_at' => now()->subMinutes(6)]);

        $this->getJson("/sync/warehouses/{$warehouse->id}/sync/{$id}")
            ->assertOk()->assertJsonPath('status', 'failed')
            ->assertJsonPath('error_code', 'XLWMS_DE_SYNC_TIMEOUT')
            ->assertJsonPath('erp_stock_updated', false);
        $this->postJson("/sync/warehouses/{$warehouse->id}/sync")->assertStatus(202);
        Queue::assertPushed(RunDeTianyunWarehouseSync::class, 2);
    }

    public function test_approved_dev_button_applies_only_exact_matched_de_sku(): void
    {
        Queue::fake();
        $de = $this->warehouse();
        $us = Warehouse::query()->where('code', 'US-SM')->firstOrFail();
        $this->connection($de);
        $sku = 'DE-MANUAL-APPLY';
        $product = Product::query()->create([
            'sku' => $sku, 'name' => 'Synthetic DE matched item',
        ]);
        $inventory = WarehouseInventory::class;
        $inventory::query()->create(['warehouse_id' => $de->id, 'product_id' => $product->id,
            'quantity_on_hand' => 7, 'quantity_reserved' => 0]);
        $inventory::query()->create(['warehouse_id' => $us->id, 'product_id' => $product->id,
            'quantity_on_hand' => 80, 'quantity_reserved' => 0]);
        config()->set('xlwms.inventory_apply_enabled', true);
        config()->set('xlwms.de_inventory_dev_apply_enabled', true);
        config()->set('xlwms.lot_reconciliation_v2_enabled', true);
        config()->set('inventory_sku_gate.enabled', true);

        $gateway = \Mockery::mock(XlwmsInventoryGateway::class);
        $gateway->shouldReceive('productPage')->andReturn([
            'code' => 200, 'data' => ['records' => [['sku' => $sku,
                'productName' => 'Synthetic DE matched item']], 'total' => 1,
                'page' => 1, 'pageSize' => 10, 'pages' => 1], 'msg' => 'OK',
        ]);
        $gateway->shouldReceive('integratedInventoryPage')->andReturn([
            'code' => 200, 'data' => ['records' => [['sku' => $sku, 'whCode' => 'Hannover',
                'stockType' => 0, 'productTotalAmount' => 10,
                'productStockDtl' => ['availableAmount' => 10, 'lockAmount' => 0,
                    'transportAmount' => 0]]], 'total' => 1, 'page' => 1,
                'pageSize' => 10, 'pages' => 1], 'msg' => 'OK',
        ]);
        $this->app->instance(XlwmsInventoryGateway::class, $gateway);
        $preview = $this->app->make(XlwmsInventorySyncService::class)
            ->sync(false, 'DE');
        config()->set('xlwms.de_inventory_first_apply_approved_checksum', $preview['checksum']);

        $actor = $this->actor(['sync.view', 'sync.operate']);
        $start = $this->actingAs($actor)->postJson("/sync/warehouses/{$de->id}/sync")
            ->assertStatus(202)->assertJsonPath('mode', 'apply_if_verified');
        $id = (int) $start->json('run_id');
        $this->app->call([new RunDeTianyunWarehouseSync($id), 'handle']);
        $this->getJson("/sync/warehouses/{$de->id}/sync/{$id}")
            ->assertOk()->assertJsonPath('status', 'applied')
            ->assertJsonPath('erp_stock_updated', true)
            ->assertJsonPath('mapped_sku_count', 1);
        self::assertSame(10, (int) $inventory::query()->where('warehouse_id', $de->id)
            ->where('product_id', $product->id)->value('quantity_on_hand'));
        self::assertSame(80, (int) $inventory::query()->where('warehouse_id', $us->id)
            ->where('product_id', $product->id)->value('quantity_on_hand'));
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::query()->where('code', 'DE')->firstOrFail();
    }

    private function connection(Warehouse $warehouse): void
    {
        // Synthetic data only: these are not real credential values.
        DB::table('warehouse_openapi_connections')->insert([
            'warehouse_id' => $warehouse->id, 'provider' => 'xlwms',
            'app_key_encrypted' => 'TEST_KEY_ONLY',
            'app_secret_encrypted' => 'TEST_SECRET_ONLY',
            'oms_warehouse_code' => 'Hannover', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param list<string> $permissions */
    private function actor(array $permissions): User
    {
        $role = Role::create(['key' => 'de-manual-sync-'.uniqid(), 'label' => 'DE Manual Sync']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $actor = User::factory()->create();
        $actor->roles()->sync([$role->id]);

        return $actor;
    }
}
