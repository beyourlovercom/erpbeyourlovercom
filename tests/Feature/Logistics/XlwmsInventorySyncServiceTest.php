<?php

namespace Tests\Feature\Logistics;

use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventoryLot;
use App\Modules\Logistics\Application\Xlwms\XlwmsInventoryPageParser;
use App\Modules\Logistics\Application\Xlwms\XlwmsInventorySyncService;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsInventorySnapshot;
use App\Modules\Logistics\Application\Xlwms\XlwmsInventoryGateway;
use App\Modules\Products\Application\Contracts\ProductRepository;
use App\Modules\Products\Application\Contracts\SkuAliasRepository;
use App\Modules\Products\Application\Contracts\SkuReconciliationRepository;
use App\Modules\Products\Application\SkuIdentityResolver;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductSkuAlias;
use App\Modules\Products\Application\SkuMatchKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    /** @return array{0:Warehouse,1:WarehouseOpenApiConnection,2:list<Product>} */
    private function fixture(array $skus): array
    {
        $warehouse = Warehouse::query()->where('code', 'US-SM')->firstOrFail();
        $connection = WarehouseOpenApiConnection::query()->updateOrCreate(['warehouse_id' => $warehouse->id, 'provider' => 'xlwms'], ['app_key_encrypted' => 'KEY', 'app_secret_encrypted' => 'SECRET', 'oms_warehouse_code' => 'US-SM']);
        $products = array_map(fn (string $sku): Product => Product::query()->firstOrCreate(['sku' => $sku], ['name' => $sku]), $skus);
        return [$warehouse, $connection, $products];
    }

    private function service(array $products, array $catalogSkus, ?array $canonicalSkus = null, ?array $inventorySkus = null, int $locked = 0): XlwmsInventorySyncService
    {
        $gateway = Mockery::mock(XlwmsInventoryGateway::class);
        $gateway->shouldReceive('productPage')->andReturnUsing(fn (): array => ['code' => 200, 'data' => ['records' => array_map(fn (string $sku): array => ['sku' => $sku, 'productName' => $sku], $catalogSkus), 'total' => count($catalogSkus), 'page' => 1, 'pageSize' => 10, 'pages' => 1], 'msg' => 'OK']);
        $gateway->shouldReceive('integratedInventoryPage')->andReturnUsing(function () use ($catalogSkus, $inventorySkus, $locked): array {
            $records = [];
            foreach (($inventorySkus ?? $catalogSkus) as $index => $sku) $records[] = ['sku' => $sku, 'whCode' => 'US-SM', 'stockType' => 0, 'productTotalAmount' => $index === 0 ? 10 : 5, 'productStockDtl' => ['availableAmount' => ($index === 0 ? 10 : 5) - $locked, 'lockAmount' => $locked, 'transportAmount' => 0]];
            return ['code' => 200, 'data' => ['records' => $records, 'total' => count($records), 'page' => 1, 'pageSize' => 10, 'pages' => 1], 'msg' => 'OK'];
        });
        $resolver = new SkuIdentityResolver($this->app->make(ProductRepository::class), $this->app->make(SkuAliasRepository::class), $this->app->make(SkuReconciliationRepository::class));
        $audit = Mockery::mock(AuditRecorder::class);
        $audit->shouldReceive('record')->zeroOrMoreTimes();
        $this->app->instance(AuditRecorder::class, $audit);
        return new XlwmsInventorySyncService($gateway, $resolver, new XlwmsInventoryPageParser(), $this->app->make(AuditRecorder::class));
    }
}
