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

    public function test_historical_shimai_pi_alias_is_not_automatically_approved_for_oms(): void
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
        self::assertSame('blocked', $result['status']);
        self::assertSame(1, $result['unmatched_sku_count']);
        self::assertSame(0, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)
            ->where('product_id', $products[0]->id)->count());
    }

    public function test_normal_outbound_oms_lock_does_not_require_matching_local_erp_reservation(): void
    {
        [$warehouse, , $products] = $this->fixture(['SKU-A']);
        WarehouseInventory::query()->create(['warehouse_id'=>$warehouse->id, 'product_id'=>$products[0]->id,
            'quantity_on_hand'=>3, 'quantity_reserved'=>0]);
        $service=$this->service($products,['SKU-A'],null,['SKU-A'],1,['SKU-A'=>10]);
        config()->set('xlwms.inventory_apply_enabled', true);
        $preview=$service->sync(false);
        self::assertSame('read_only', $preview['status']);
        self::assertSame(1, $preview['oms_locked_qty']);
        self::assertSame(9, $preview['oms_available_qty']);
        self::assertSame('applied', $service->sync(true)['status']);
        $inventory=WarehouseInventory::query()->where('warehouse_id',$warehouse->id)
            ->where('product_id',$products[0]->id)->firstOrFail();
        self::assertSame(10,(int)$inventory->quantity_on_hand);
        self::assertSame(0,(int)$inventory->quantity_reserved);
    }

    public function test_provider_locked_units_limit_erp_allocation_without_inventing_local_reservations(): void
    {
        [$warehouse, , $products] = $this->fixture(['SKU-A']);
        WarehouseInventory::query()->create(['warehouse_id'=>$warehouse->id, 'product_id'=>$products[0]->id,
            'quantity_on_hand'=>10, 'quantity_reserved'=>0]);
        WarehouseInventoryLot::query()->create(['warehouse_id'=>$warehouse->id, 'product_id'=>$products[0]->id,
            'stock_status'=>'sellable','putaway_date'=>'2026-10-01','quantity'=>10]);
        $remote=$this->service($products,['SKU-A'],null,['SKU-A'],1,['SKU-A'=>10]);
        config()->set('xlwms.lot_reconciliation_v2_enabled',true);
        config()->set('xlwms.inventory_apply_enabled',true);
        $preview=$remote->sync(false);
        self::assertSame('read_only',$preview['status']);
        self::assertSame(1,$preview['oms_locked_qty']);
        config()->set('xlwms.inventory_first_apply_approved_checksum',$preview['checksum']);
        self::assertSame('applied',$remote->sync(true)['status']);
        self::assertSame(9,$this->usSmAvailable($warehouse->id,$products[0]->id));
        self::assertSame(0,(int)WarehouseInventory::query()->where('warehouse_id',$warehouse->id)
            ->where('product_id',$products[0]->id)->value('quantity_reserved'));
        $row=XlwmsInventorySnapshot::query()->findOrFail($preview['snapshot_id'])
            ->rows()->where('provider_sku','SKU-A')->firstOrFail();
        self::assertSame(10,(int)$row->oms_total);
        self::assertSame(9,(int)$row->oms_available);
        self::assertSame(1,(int)$row->oms_locked);

        // The warehouse ships the pending order: remote lock clears but
        // physical QOH does not change until the later confirmed dispatch.
        $released=$this->service($products,['SKU-A'],null,['SKU-A'],0,['SKU-A'=>10]);
        self::assertSame('applied',$released->sync(true)['status']);
        self::assertSame(10,$this->usSmAvailable($warehouse->id,$products[0]->id));
    }

    public function test_erp_reserved_stock_and_oms_external_lock_use_conservative_shared_cap(): void
    {
        [$warehouse, , $products] = $this->fixture(['SKU-A']);
        WarehouseInventory::query()->create(['warehouse_id'=>$warehouse->id, 'product_id'=>$products[0]->id,
            'quantity_on_hand'=>10, 'quantity_reserved'=>2]);
        WarehouseInventoryLot::query()->create(['warehouse_id'=>$warehouse->id, 'product_id'=>$products[0]->id,
            'stock_status'=>'sellable','putaway_date'=>'2026-10-01','quantity'=>10]);
        config()->set('xlwms.lot_reconciliation_v2_enabled',true);
        config()->set('xlwms.inventory_apply_enabled',true);
        $service=$this->service($products,['SKU-A'],null,['SKU-A'],1,['SKU-A'=>10]);
        config()->set('xlwms.inventory_first_apply_approved_checksum',$service->sync(false)['checksum']);
        self::assertSame('applied',$service->sync(true)['status']);
        self::assertSame(7,$this->usSmAvailable($warehouse->id,$products[0]->id));
        self::assertSame(2,(int)WarehouseInventory::query()->where('warehouse_id',$warehouse->id)
            ->where('product_id',$products[0]->id)->value('quantity_reserved'));
    }

    public function test_blocked_provider_snapshot_closes_allocation_until_reconciled(): void
    {
        [$warehouse, , $products] = $this->fixture(['SKU-A']);
        WarehouseInventory::query()->create(['warehouse_id'=>$warehouse->id, 'product_id'=>$products[0]->id,
            'quantity_on_hand'=>10, 'quantity_reserved'=>0]);
        WarehouseInventoryLot::query()->create(['warehouse_id'=>$warehouse->id, 'product_id'=>$products[0]->id,
            'stock_status'=>'sellable','putaway_date'=>'2026-10-01','quantity'=>10]);
        config()->set('xlwms.lot_reconciliation_v2_enabled',true);
        config()->set('xlwms.inventory_apply_enabled',true);
        $service=$this->service($products,['SKU-A'],null,['SKU-A'],1,['SKU-A'=>10]);
        config()->set('xlwms.inventory_first_apply_approved_checksum',$service->sync(false)['checksum']);
        self::assertSame('applied',$service->sync(true)['status']);
        self::assertSame(9,$this->usSmAvailable($warehouse->id,$products[0]->id));
        // Invalid OMS delta > R7 max must block the *next* snapshot.
        $failed=$this->service($products,['SKU-A'],null,['SKU-A'],1,['SKU-A'=>25]);
        self::assertSame('blocked',$failed->sync(true)['status']);
        self::assertSame(0,$this->usSmAvailable($warehouse->id,$products[0]->id));
        self::assertSame(10,(int)WarehouseInventory::query()->where('warehouse_id',$warehouse->id)
            ->where('product_id',$products[0]->id)->value('quantity_on_hand'));
    }

    private function usSmAvailable(int $warehouseId, int $productId): int
    {
        $choices=app(EloquentInventoryAvailabilityReader::class)->forProductIds([$productId]);
        return (int) collect($choices[$productId])->firstWhere('warehouse_id',$warehouseId)['quantity_available'];
    }

    public function test_v2_first_apply_requires_exact_reviewed_checksum_without_mutating_inventory(): void
    {
        [$warehouse, , $products] = $this->fixture(['SKU-A']);
        WarehouseInventory::query()->create(['warehouse_id' => $warehouse->id,
            'product_id' => $products[0]->id, 'quantity_on_hand' => 3, 'quantity_reserved' => 0]);
        $service = $this->service($products, ['SKU-A'], null, ['SKU-A'], 0, ['SKU-A' => 10]);
        config()->set('xlwms.lot_reconciliation_v2_enabled', true);
        config()->set('xlwms.inventory_apply_enabled', true);

        $readOnly = $service->sync(false);
        self::assertSame('read_only', $readOnly['status']);
        self::assertSame('blocked', $service->sync(true)['status']);
        self::assertSame(3, WarehouseInventory::query()->where('product_id', $products[0]->id)->value('quantity_on_hand'));
        self::assertSame(0, XlwmsInventoryLotReconciliation::query()->count());

        config()->set('xlwms.inventory_first_apply_approved_checksum', str_repeat('f', 64));
        self::assertSame('blocked', $service->sync(true)['status']);
        config()->set('xlwms.inventory_first_apply_approved_checksum', $readOnly['checksum']);
        self::assertSame('applied', $service->sync(true)['status']);
        self::assertSame(10, WarehouseInventory::query()->where('product_id', $products[0]->id)->value('quantity_on_hand'));
    }

    public function test_v2_recurring_large_delta_blocks_entire_batch_after_approved_initial_write(): void
    {
        [$warehouse, , $products] = $this->fixture(['SKU-A', 'SKU-B']);
        foreach ($products as $p) {
            WarehouseInventory::query()->create(['warehouse_id'=>$warehouse->id,
                'product_id'=>$p->id,'quantity_on_hand'=>10,'quantity_reserved'=>0]);
        }
        $service = $this->service($products, ['SKU-A','SKU-B'], null,
            ['SKU-A','SKU-B'],0,['SKU-A'=>10,'SKU-B'=>10]);
        config()->set('xlwms.lot_reconciliation_v2_enabled', true);
        config()->set('xlwms.inventory_apply_enabled', true);
        config()->set('xlwms.inventory_first_apply_approved_checksum', $service->sync(false)['checksum']);
        self::assertSame('applied', $service->sync(true)['status']);

        $anomalous = $this->service($products, ['SKU-A','SKU-B'], null,
            ['SKU-A','SKU-B'],0,['SKU-A'=>11,'SKU-B'=>25]);
        $result = $anomalous->sync(true);
        self::assertSame('blocked', $result['status']);
        self::assertSame(10, WarehouseInventory::where('warehouse_id', $warehouse->id)
            ->where('product_id', $products[0]->id)->value('quantity_on_hand'));
        self::assertSame(10, WarehouseInventory::where('warehouse_id', $warehouse->id)
            ->where('product_id', $products[1]->id)->value('quantity_on_hand'));
        self::assertSame(2, XlwmsInventoryLotReconciliation::query()->count());
        self::assertContains('OMS_SKU_DELTA_EXCEEDS_AUTO_LIMIT',
            collect($result['mismatches'])->pluck('reason')->all());
    }

    public function test_v2_recurring_small_confirmed_delta_can_apply_with_automatic_limits(): void
    {
        [$warehouse, , $products] = $this->fixture(['SKU-A']);
        WarehouseInventory::query()->create(['warehouse_id'=>$warehouse->id,
            'product_id'=>$products[0]->id,'quantity_on_hand'=>10,'quantity_reserved'=>0]);
        $initial = $this->service($products,['SKU-A'],null,['SKU-A'],0,['SKU-A'=>10]);
        config()->set('xlwms.lot_reconciliation_v2_enabled', true);
        config()->set('xlwms.inventory_apply_enabled', true);
        config()->set('xlwms.inventory_first_apply_approved_checksum', $initial->sync(false)['checksum']);
        self::assertSame('applied', $initial->sync(true)['status']);

        $next = $this->service($products,['SKU-A'],null,['SKU-A'],0,['SKU-A'=>12]);
        self::assertSame('applied', $next->sync(true)['status']);
        self::assertSame(12, WarehouseInventory::query()->where('warehouse_id',$warehouse->id)
            ->where('product_id',$products[0]->id)->value('quantity_on_hand'));
    }

    public function test_v2_auto_sync_blocks_changed_provider_sku_coverage_even_if_delta_is_small(): void
    {
        [$warehouse, , $products] = $this->fixture(['SKU-A', 'SKU-B']);
        foreach ($products as $p) {
            WarehouseInventory::query()->create(['warehouse_id'=>$warehouse->id,
                'product_id'=>$p->id,'quantity_on_hand'=>5,'quantity_reserved'=>0]);
        }
        $baseline = $this->service($products,['SKU-A','SKU-B'],null,['SKU-A'],0,['SKU-A'=>5]);
        config()->set('xlwms.lot_reconciliation_v2_enabled', true);
        config()->set('xlwms.inventory_apply_enabled', true);
        config()->set('xlwms.inventory_first_apply_approved_checksum', $baseline->sync(false)['checksum']);
        self::assertSame('applied', $baseline->sync(true)['status']);

        $changed = $this->service($products,['SKU-A','SKU-B'],null,['SKU-A','SKU-B'],0,['SKU-A'=>5,'SKU-B'=>5]);
        $result = $changed->sync(true);
        self::assertSame('blocked', $result['status']);
        self::assertContains('OMS_STOCK_SKU_COVERAGE_OR_IDENTITY_CHANGED',
            collect($result['mismatches'])->pluck('reason')->all());
        self::assertSame(5, WarehouseInventory::query()->where('warehouse_id',$warehouse->id)
            ->where('product_id',$products[1]->id)->value('quantity_on_hand'));
    }

    public function test_v2_unobserved_erp_stock_is_preserved_but_not_allocatable(): void
    {
        [$warehouse, , $products] = $this->fixture(['SKU-A', 'ERP-ONLY']);
        WarehouseInventory::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $products[0]->id, 'quantity_on_hand' => 10, 'quantity_reserved' => 0]);
        WarehouseInventory::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $products[1]->id, 'quantity_on_hand' => 20, 'quantity_reserved' => 0]);
        $otherWarehouse = Warehouse::query()->where('code', 'US-LA')->firstOrFail();
        WarehouseInventory::query()->create(['warehouse_id' => $otherWarehouse->id, 'product_id' => $products[1]->id, 'quantity_on_hand' => 7, 'quantity_reserved' => 2]);
        WarehouseInventoryLot::query()->create(['warehouse_id' => $warehouse->id, 'product_id' => $products[0]->id, 'stock_status' => 'sellable', 'putaway_date' => '2026-10-01', 'quantity' => 10]);
        $service = $this->service($products, ['SKU-A'], null, ['SKU-A'], 0, ['SKU-A' => 10]);
        config()->set('xlwms.lot_reconciliation_v2_enabled', true);
        config()->set('xlwms.inventory_apply_enabled', true);
        config()->set('xlwms.inventory_first_apply_approved_checksum', $service->sync(false)['checksum']);
        self::assertSame('applied', $service->sync(true)['status']);

        $available = app(EloquentInventoryAvailabilityReader::class)->forProductIds([$products[0]->id, $products[1]->id]);
        $covered = collect($available[$products[0]->id])->firstWhere('warehouse_id', $warehouse->id);
        $missing = collect($available[$products[1]->id])->firstWhere('warehouse_id', $warehouse->id);
        self::assertSame(10, $covered['quantity_available']);
        self::assertSame(20, $missing['quantity_on_hand']);
        self::assertSame(0, $missing['quantity_available']);
        self::assertSame(20, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)
            ->where('product_id', $products[1]->id)->value('quantity_on_hand'));
        $other = collect($available[$products[1]->id])->firstWhere('warehouse_id', $otherWarehouse->id);
        self::assertSame(5, $other['quantity_available']);
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
        config()->set('xlwms.inventory_first_apply_approved_checksum', $service->sync(false)['checksum']);

        $result = $service->sync(true);

        self::assertSame('applied', $result['status']);
        self::assertSame(5, WarehouseInventoryLot::query()->findOrFail($old->id)->quantity);
        self::assertSame(5, DB::table('xlwms_inventory_unverified_ages')->where('product_id', $products[0]->id)->value('unknown_age_quantity'));
        self::assertSame(3, DB::table('xlwms_inventory_unverified_ages')->where('product_id', $products[1]->id)->value('unknown_age_quantity'));
        self::assertSame(8, DB::table('xlwms_inventory_unverified_ages')->where('product_id', $products[2]->id)->value('unknown_age_quantity'));
        self::assertSame(4, XlwmsInventoryLotReconciliation::query()->count());
        $available = app(EloquentInventoryAvailabilityReader::class)->forProductIds([$products[0]->id]);
        $unknownAgeProduct = collect($available[$products[0]->id])->firstWhere('warehouse_id', $warehouse->id);
        self::assertSame(0, $unknownAgeProduct['quantity_available']);
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
