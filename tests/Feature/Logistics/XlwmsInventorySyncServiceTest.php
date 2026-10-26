<?php

namespace Tests\Feature\Logistics;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\EloquentInventoryAvailabilityReader;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventoryLot;
use App\Modules\Logistics\Application\Xlwms\XlwmsInventoryPageParser;
use App\Modules\Logistics\Application\Xlwms\XlwmsInventorySyncService;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsInventorySnapshot;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsInventoryLotReconciliation;
use App\Modules\Logistics\Application\Xlwms\XlwmsInventoryGateway;
use App\Modules\Products\Application\Contracts\ProductRepository;
use App\Modules\Products\Application\Contracts\SkuAliasRepository;
use App\Modules\Products\Application\Contracts\SkuReconciliationRepository;
use App\Modules\Products\Application\SkuIdentityResolver;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductSkuAlias;
use App\Modules\Products\Application\SkuMatchKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

final class XlwmsInventorySyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('xlwms_inventory_snapshots')) {
            $migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_26_130000_create_xlwms_inventory_snapshots.php';
            $migration->up();
        }
        if (! Schema::hasTable('xlwms_inventory_unverified_ages')) {
            $migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_26_140000_create_xlwms_lot_reconciliation_v2_tables.php';
            $migration->up();
        }
    }

    public function test_real_shape_dry_run_apply_and_idempotence_are_transactional(): void
    {
        [$warehouse, $connection, $products] = $this->fixture(['SKU-A', 'SKU-B']);
        WarehouseInventory::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $products[0]->id, 'quantity_on_hand' => 1, 'quantity_reserved' => 0]);
        $service = $this->service($products, ['SKU-A', 'SKU-B']);

        $dry = $service->sync();
        self::assertSame('read_only', $dry['status']);
        self::assertSame(1, WarehouseInventory::query()->where('product_id', $products[0]->id)->value('quantity_on_hand'));

        config()->set('xlwms.inventory_apply_enabled', true);
        $applied = $service->sync(true);
        self::assertSame('applied', $applied['status']);
        self::assertSame(10, WarehouseInventory::query()->where('product_id', $products[0]->id)->value('quantity_on_hand'));
        self::assertSame(5, WarehouseInventory::query()->where('product_id', $products[1]->id)->value('quantity_on_hand'));
        // The first post-apply read records the new ERP baseline; subsequent identical reads are idempotent.
        $stable = $service->sync(true);
        self::assertSame('applied', $stable['status']);
        self::assertSame($stable['snapshot_id'], $service->sync(true)['snapshot_id']);
        self::assertSame(2, XlwmsInventorySnapshot::query()->count());
    }

    public function test_unmatched_sku_blocks_without_partial_quantity_write(): void
    {
        [$warehouse, , $products] = $this->fixture(['SKU-A']);
        WarehouseInventory::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $products[0]->id, 'quantity_on_hand' => 3, 'quantity_reserved' => 0]);
        $service = $this->service($products, ['SKU-A', 'SKU-B'], ['SKU-A']);
        config()->set('xlwms.inventory_apply_enabled', true);

        $result = $service->sync(true);
        self::assertSame('blocked', $result['status']);
        self::assertSame(3, WarehouseInventory::query()->where('product_id', $products[0]->id)->value('quantity_on_hand'));
        self::assertSame(1, WarehouseInventory::query()->where('product_id', $products[0]->id)->count());
        $unmatchedRow = XlwmsInventorySnapshot::query()->findOrFail($result['snapshot_id'])->rows()
            ->where('provider_sku', 'SKU-B')->firstOrFail();
        self::assertSame('unmatched', $unmatchedRow->resolution_status);
        self::assertSame(5, $unmatchedRow->oms_total);
        self::assertSame(15, $result['oms_good_qty']);
    }

    public function test_lot_state_blocks_apply_without_quantity_write(): void
    {
        [$warehouse, , $products] = $this->fixture(['SKU-A']);
        WarehouseInventory::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $products[0]->id, 'quantity_on_hand' => 3, 'quantity_reserved' => 0]);
        WarehouseInventoryLot::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $products[0]->id, 'stock_status' => 'good', 'putaway_date' => '2026-01-01', 'quantity' => 3]);
        $service = $this->service($products, ['SKU-A']);
        config()->set('xlwms.inventory_apply_enabled', true);

        $result = $service->sync(true);
        self::assertSame('blocked', $result['status']);
        self::assertSame(3, WarehouseInventory::query()->where('product_id', $products[0]->id)->value('quantity_on_hand'));
    }

    public function test_catalog_only_skus_without_stock_ledger_do_not_force_zero_or_block(): void
    {
        [$warehouse, , $products] = $this->fixture(['SKU-A']);
        $service = $this->service($products, ['SKU-A', 'CATALOG-OTHER-WAREHOUSE'], null, ['SKU-A']);
        config()->set('xlwms.inventory_apply_enabled', true);
        $result = $service->sync(true);
        self::assertSame('applied', $result['status']);
        self::assertSame(0, (int) $result['unmatched_sku_count']);
        $catalogRow = XlwmsInventorySnapshot::query()->findOrFail($result['snapshot_id'])->rows()
            ->where('provider_sku', 'CATALOG-OTHER-WAREHOUSE')->firstOrFail();
        self::assertSame('catalog_only', $catalogRow->resolution_status);
        self::assertNull($catalogRow->oms_total);
        self::assertSame(10, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)
            ->where('product_id', $products[0]->id)->value('quantity_on_hand'));
    }

    public function test_existing_documented_shimai_pi_alias_can_reconcile_sku_identity(): void
    {
        [$warehouse, , $products] = $this->fixture(['VM467A']);
        ProductSkuAlias::query()->create([
            'product_id' => $products[0]->id, 'alias_sku' => 'VM467',
            'match_key' => SkuMatchKey::compute('VM467'),
            'source_type' => 'smgj_pi',
            'source_reference' => '美西-世迈仓PI(1).xlsx|PI|2026-09-01',
            'notes' => 'Verified supplier source alias',
        ]);
        $service = $this->service($products, ['VM467'], null, ['VM467']);
        config()->set('xlwms.inventory_apply_enabled', true);
        $result = $service->sync(true);
        self::assertSame('applied', $result['status']);
        self::assertSame(10, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)
            ->where('product_id', $products[0]->id)->value('quantity_on_hand'));
    }

    public function test_vendor_locked_quantity_blocks_local_overstatement(): void
    {
        [$warehouse, , $products] = $this->fixture(['SKU-A']);
        WarehouseInventory::query()->create(['warehouse_id' => $warehouse->id,
            'product_id' => $products[0]->id, 'quantity_on_hand' => 3, 'quantity_reserved' => 0]);
        $service = $this->service($products, ['SKU-A'], null, ['SKU-A'], 2);
        config()->set('xlwms.inventory_apply_enabled', true);
        $result = $service->sync(true);
        self::assertSame('blocked', $result['status']);
        self::assertSame(3, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)
            ->where('product_id', $products[0]->id)->value('quantity_on_hand'));
    }

    public function test_v2_unobserved_erp_stock_is_preserved_but_not_allocatable(): void
    {
        [$warehouse, , $products] = $this->fixture(['SKU-A', 'ERP-ONLY']);
        WarehouseInventory::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $products[0]->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);
        WarehouseInventory::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $products[1]->id, 'quantity_on_hand' => 20, 'quantity_reserved' => 0]);
        WarehouseInventoryLot::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $products[0]->id, 'stock_status' => 'sellable', 'putaway_date' => '2026-10-01', 'quantity' => 10]);
        $service = $this->service($products, ['SKU-A'], null, ['SKU-A'], 0, ['SKU-A' => 10]);
        config()->set('xlwms.lot_reconciliation_v2_enabled', true);
        config()->set('xlwms.inventory_apply_enabled', true);
        self::assertSame('applied', $service->sync(true)['status']);

        $available = app(EloquentInventoryAvailabilityReader::class)->forProductIds([$products[0]->id, $products[1]->id]);
        $covered = collect($available[$products[0]->id])->firstWhere('warehouse_id', $warehouse->id);
        $missing = collect($available[$products[1]->id])->firstWhere('warehouse_id', $warehouse->id);
        self::assertSame(10, $covered['quantity_available']);
        self::assertSame(20, $missing['quantity_on_hand']);
        self::assertSame(0, $missing['quantity_available']);
        self::assertSame(20, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)
            ->where('product_id', $products[1]->id)->value('quantity_on_hand'));
    }

    public function test_v2_unmatched_provider_sku_prevents_partial_apply(): void
    {
        [$warehouse, , $products] = $this->fixture(['SKU-A']);
        WarehouseInventory::query()->create(['warehouse_id' => $warehouse->id,
            'product_id' => $products[0]->id, 'quantity_on_hand' => 20, 'quantity_reserved' => 0]);
        $service = $this->service($products, ['SKU-A', 'SKU-NEW'], null,
            ['SKU-A', 'SKU-NEW'], 0, ['SKU-A' => 10, 'SKU-NEW' => 5]);
        config()->set('xlwms.lot_reconciliation_v2_enabled', true);
        config()->set('xlwms.inventory_apply_enabled', true);

        $result = $service->sync(true);
        self::assertSame('blocked', $result['status']);
        self::assertSame(20, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)
            ->where('product_id', $products[0]->id)->value('quantity_on_hand'));
        self::assertSame(0, DB::table('xlwms_inventory_lot_reconciliations')->count());
    }

    public function test_v2_reduces_oldest_lot_and_quarantines_missing_dates(): void
    {
        [$warehouse, , $products] = $this->fixture(['SKU-A', 'SKU-B', 'SKU-C', 'SKU-D']);
        foreach ($products as $product) WarehouseInventory::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity_on_hand' => 20, 'quantity_reserved' => 0]);
        $old = WarehouseInventoryLot::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $products[3]->id, 'stock_status' => 'sellable', 'putaway_date' => '2026-01-01', 'quantity' => 20]);
        WarehouseInventoryLot::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $products[3]->id, 'stock_status' => 'sellable', 'putaway_date' => '2026-02-01', 'quantity' => 5]);
        $service = $this->service($products, ['SKU-A', 'SKU-B', 'SKU-C', 'SKU-D'], null, ['SKU-A', 'SKU-B', 'SKU-C', 'SKU-D'], 0, ['SKU-A' => 5, 'SKU-B' => 3, 'SKU-C' => 8, 'SKU-D' => 10]);
        config()->set('xlwms.lot_reconciliation_v2_enabled', true);
        config()->set('xlwms.inventory_apply_enabled', true);

        $result = $service->sync(true);

        self::assertSame('applied', $result['status']);
        self::assertSame(5, WarehouseInventoryLot::query()->findOrFail($old->id)->quantity);
        self::assertSame(5, DB::table('xlwms_inventory_unverified_ages')->where('product_id', $products[0]->id)->value('unknown_age_quantity'));
        self::assertSame(3, DB::table('xlwms_inventory_unverified_ages')->where('product_id', $products[1]->id)->value('unknown_age_quantity'));
        self::assertSame(8, DB::table('xlwms_inventory_unverified_ages')->where('product_id', $products[2]->id)->value('unknown_age_quantity'));
        self::assertSame(4, XlwmsInventoryLotReconciliation::query()->count());
    }

    public function test_v2_protected_lot_blocks_every_sku_without_partial_update(): void
    {
        [$warehouse, , $products] = $this->fixture(['SKU-A', 'SKU-B']);
        foreach ($products as $product) WarehouseInventory::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity_on_hand' => 20, 'quantity_reserved' => 0]);
        $batch = DB::table('inventory_sync_batches')->insertGetId(['warehouse_id' => $warehouse->id, 'source_type' => 'api', 'sync_mode' => 'full', 'status' => 'confirmed', 'preview_total_rows' => 1, 'preview_unique_skus' => 1, 'created_at' => now(), 'updated_at' => now()]);
        WarehouseInventoryLot::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $products[0]->id, 'stock_status' => 'sellable', 'putaway_date' => '2026-01-01', 'quantity' => 20, 'sync_batch_id' => $batch]);
        $service = $this->service($products, ['SKU-A', 'SKU-B'], null, ['SKU-A', 'SKU-B'], 0, ['SKU-A' => 5, 'SKU-B' => 5]);
        config()->set('xlwms.lot_reconciliation_v2_enabled', true);
        config()->set('xlwms.inventory_apply_enabled', true);

        $result = $service->sync(true);

        self::assertSame('blocked', $result['status']);
        self::assertSame(20, WarehouseInventory::query()->where('product_id', $products[0]->id)->value('quantity_on_hand'));
        self::assertSame(20, WarehouseInventory::query()->where('product_id', $products[1]->id)->value('quantity_on_hand'));
        self::assertDatabaseHas('xlwms_inventory_snapshots', ['id' => $result['snapshot_id'], 'status' => 'blocked']);
    }

    /** @return array{0:Warehouse,1:WarehouseOpenApiConnection,2:list<Product>} */
    private function fixture(array $skus): array
    {
        $warehouse = Warehouse::query()->where('code', 'US-SM')->firstOrFail();
        $connection = WarehouseOpenApiConnection::query()->updateOrCreate(['warehouse_id' => $warehouse->id, 'provider' => 'xlwms'], ['app_key_encrypted' => 'KEY', 'app_secret_encrypted' => 'SECRET', 'oms_warehouse_code' => 'US-SM']);
        $products = array_map(fn (string $sku): Product => Product::query()->firstOrCreate(['sku' => $sku], ['name' => $sku]), $skus);
        return [$warehouse, $connection, $products];
    }

    private function service(array $products, array $catalogSkus, ?array $canonicalSkus = null, ?array $inventorySkus = null, int $locked = 0, array $totals = []): XlwmsInventorySyncService
    {
        $gateway = Mockery::mock(XlwmsInventoryGateway::class);
        $gateway->shouldReceive('productPage')->andReturnUsing(fn (): array => ['code' => 200, 'data' => ['records' => array_map(fn (string $sku): array => ['sku' => $sku, 'productName' => $sku], $catalogSkus), 'total' => count($catalogSkus), 'page' => 1, 'pageSize' => 10, 'pages' => 1], 'msg' => 'OK']);
        $gateway->shouldReceive('integratedInventoryPage')->andReturnUsing(function () use ($catalogSkus, $inventorySkus, $locked, $totals): array {
            $records = [];
            foreach (($inventorySkus ?? $catalogSkus) as $index => $sku) { $total = $totals[$sku] ?? ($index === 0 ? 10 : 5); $records[] = ['sku' => $sku, 'whCode' => 'US-SM', 'stockType' => 0, 'productTotalAmount' => $total, 'productStockDtl' => ['availableAmount' => $total - $locked, 'lockAmount' => $locked, 'transportAmount' => 0]]; }
            return ['code' => 200, 'data' => ['records' => $records, 'total' => count($records), 'page' => 1, 'pageSize' => 10, 'pages' => 1], 'msg' => 'OK'];
        });
        $resolver = new SkuIdentityResolver($this->app->make(ProductRepository::class), $this->app->make(SkuAliasRepository::class), $this->app->make(SkuReconciliationRepository::class));
        $audit = Mockery::mock(AuditRecorder::class);
        $audit->shouldReceive('record')->zeroOrMoreTimes();
        $this->app->instance(AuditRecorder::class, $audit);
        return new XlwmsInventorySyncService($gateway, $resolver, new XlwmsInventoryPageParser(), $this->app->make(AuditRecorder::class));
    }
}
