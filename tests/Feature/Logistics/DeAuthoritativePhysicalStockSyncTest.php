<?php

namespace Tests\Feature\Logistics;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Application\Xlwms\DeAuthoritativePhysicalStockSyncService;
use App\Modules\Logistics\Application\Xlwms\XlwmsInventoryCoverage;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsInventorySnapshot;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class DeAuthoritativePhysicalStockSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_physical_stock_matches_provider_without_erasing_reservations_or_other_warehouses(): void
    {
        config()->set('xlwms.de_authoritative_auto_apply_enabled', true);
        $de = Warehouse::query()->where('code', 'DE')->firstOrFail();
        $us = Warehouse::query()->where('code', 'US-SM')->firstOrFail();
        $product = Product::query()->create(['sku' => 'DE-AUTO-OWNED', 'name' => 'Owned']);
        $outsider = Product::query()->create(['sku' => 'zuodian', 'name' => 'Other customer']);
        $deInventory = WarehouseInventory::query()->create([
            'warehouse_id' => $de->id, 'product_id' => $product->id,
            'quantity_on_hand' => 9, 'quantity_reserved' => 7,
            'provider_available_quantity' => 9,
        ]);
        WarehouseInventory::query()->create([
            'warehouse_id' => $us->id, 'product_id' => $product->id,
            'quantity_on_hand' => 50, 'quantity_reserved' => 0,
        ]);
        $snapshot = $this->snapshot($de, [
            ['sku' => 'DE-AUTO-OWNED', 'product_id' => $product->id,
                'status' => 'blocked', 'qoh' => 2, 'available' => 2, 'locked' => 0],
            // Even if another-customer inventory is incorrectly assigned an
            // ERP product ID in a stale snapshot, exclusion wins.
            ['sku' => 'zuodian', 'product_id' => $outsider->id,
                'status' => 'matched', 'qoh' => 159, 'available' => 148, 'locked' => 11],
            ['sku' => 'OTHER-ONLY-CATALOG', 'product_id' => null,
                'status' => 'catalog_only', 'qoh' => null, 'available' => null, 'locked' => null],
        ], 1);
        $service = app(DeAuthoritativePhysicalStockSyncService::class);
        $preview = $service->reconcileSnapshot($snapshot->id);
        self::assertSame(1, $preview['changed_skus']);
        self::assertSame(2, $preview['oms_total']);
        self::assertSame(['zuodian'], $preview['excluded_skus']);
        self::assertFalse($preview['stock_updated']);
        self::assertSame(9, $deInventory->fresh()->quantity_on_hand);

        $applied = $service->reconcileSnapshot($snapshot->id, true);
        self::assertTrue($applied['stock_updated']);
        self::assertSame(1, $applied['changed_skus']);
        self::assertSame(2, $deInventory->fresh()->quantity_on_hand);
        self::assertSame(7, $deInventory->fresh()->quantity_reserved);
        self::assertSame(2, $deInventory->fresh()->provider_available_quantity);
        self::assertSame(50, WarehouseInventory::query()->where('warehouse_id', $us->id)
            ->where('product_id', $product->id)->value('quantity_on_hand'));
        self::assertSame(0, WarehouseInventory::query()->where('warehouse_id', $de->id)
            ->where('product_id', $outsider->id)->count());
        self::assertSame(1, DB::table('inventory_adjustments')
            ->where('warehouse_id', $de->id)->where('product_id', $product->id)->count());
        self::assertTrue((bool) $snapshot->fresh()->summary['de_authoritative_physical_v1']);
        self::assertSame('blocked', $snapshot->fresh()->status); // Historical ledger not falsely reconciled.

        $second = $service->reconcileSnapshot($snapshot->id, true);
        self::assertSame(0, $second['changed_skus']);
        self::assertSame(1, DB::table('inventory_adjustments')
            ->where('warehouse_id', $de->id)->where('product_id', $product->id)->count());
    }

    public function test_default_off_gate_rejects_all_physical_stock_writes(): void
    {
        config()->set('xlwms.de_authoritative_auto_apply_enabled', false);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DE_AUTHORITATIVE_PHYSICAL_APPLY_DISABLED');
        app(DeAuthoritativePhysicalStockSyncService::class)->reconcileSnapshot(1, true);
    }

    public function test_unrelated_unknown_zero_and_positive_skus_are_quarantined_while_healthy_stock_applies(): void
    {
        config()->set('xlwms.de_authoritative_auto_apply_enabled', true);
        $de = Warehouse::query()->where('code', 'DE')->firstOrFail();
        $product = Product::query()->create(['sku' => 'DE-MATCHED-ONE', 'name' => 'Matched']);
        $stock = WarehouseInventory::query()->create([
            'warehouse_id' => $de->id, 'product_id' => $product->id,
            'quantity_on_hand' => 10, 'quantity_reserved' => 0,
        ]);
        $snapshot = $this->snapshot($de, [
            ['sku' => 'DE-MATCHED-ONE', 'product_id' => $product->id,
                'status' => 'matched', 'qoh' => 2, 'available' => 2, 'locked' => 0],
            ['sku' => 'UNVERIFIED-SKU', 'product_id' => null,
                'status' => 'unmatched', 'qoh' => 8, 'available' => 8, 'locked' => 0],
            ['sku' => 'UNKNOWN-ZERO', 'product_id' => null,
                'status' => 'unmatched', 'qoh' => 0, 'available' => 0, 'locked' => 0],
        ], 1);
        $result = app(DeAuthoritativePhysicalStockSyncService::class)->reconcileSnapshot($snapshot->id, true);
        self::assertSame(2, $stock->fresh()->quantity_on_hand);
        self::assertSame(2, $result['quarantined_sku_count']);
        self::assertSame([$product->id], $snapshot->fresh()->summary['de_physical_applied_product_ids']);
        self::assertSame([$product->id => 2], app(XlwmsInventoryCoverage::class)->providerAvailableByProductId($de->id));
        self::assertSame(1, DB::table('inventory_adjustments')->where('warehouse_id', $de->id)->count());
    }

    public function test_bad_provider_quantities_abort_without_any_inventory_write(): void
    {
        config()->set('xlwms.de_authoritative_auto_apply_enabled', true);
        $de = Warehouse::query()->where('code', 'DE')->firstOrFail();
        $product = Product::query()->create(['sku' => 'HEALTHY', 'name' => 'Healthy']);
        $snapshot = $this->snapshot($de, [
            ['sku' => 'HEALTHY', 'product_id' => $product->id, 'status' => 'matched', 'qoh' => 2, 'available' => 2, 'locked' => 0],
            ['sku' => 'INVALID', 'product_id' => null, 'status' => 'unmatched', 'qoh' => 8, 'available' => 3, 'locked' => 0],
        ], 1);
        try {
            app(DeAuthoritativePhysicalStockSyncService::class)->reconcileSnapshot($snapshot->id, true);
            self::fail('Invalid provider total must stop source');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('DE_PHYSICAL_INVALID_SOURCE_STOCK', $e->getMessage());
        }
        self::assertSame(0, WarehouseInventory::query()->where('warehouse_id', $de->id)->where('product_id', $product->id)->count());
        self::assertArrayNotHasKey('de_physical_applied_product_ids', $snapshot->fresh()->summary);
    }

    public function test_unrelated_local_readback_drift_does_not_invalidate_healthy_product(): void
    {
        config()->set('xlwms.de_authoritative_auto_apply_enabled', true);
        $de = Warehouse::query()->where('code', 'DE')->firstOrFail();
        $a = Product::query()->create(['sku' => 'DE-HEALTHY-A', 'name' => 'A']);
        $b = Product::query()->create(['sku' => 'DE-DRIFT-B', 'name' => 'B']);
        $snapshot = $this->snapshot($de, [
            ['sku' => $a->sku, 'product_id' => $a->id, 'status' => 'matched', 'qoh' => 2, 'available' => 2, 'locked' => 0],
            ['sku' => $b->sku, 'product_id' => $b->id, 'status' => 'matched', 'qoh' => 4, 'available' => 4, 'locked' => 0],
        ], 2);
        app(DeAuthoritativePhysicalStockSyncService::class)->reconcileSnapshot($snapshot->id, true);
        WarehouseInventory::query()->where('warehouse_id', $de->id)->where('product_id', $b->id)->update(['quantity_on_hand' => 9]);
        self::assertSame([$a->id => 2], app(XlwmsInventoryCoverage::class)->providerAvailableByProductId($de->id));
    }

    private function snapshot(Warehouse $warehouse, array $items, int $mapped): XlwmsInventorySnapshot
    {
        $snapshot = XlwmsInventorySnapshot::query()->create([
            'warehouse_id' => $warehouse->id, 'wh_code' => 'Hannover',
            'checksum' => str_repeat('a', 64), 'status' => 'blocked',
            'fetched_at' => now(), 'mapped_sku_count' => $mapped,
            'provider_sku_count' => count($items),
            'unmatched_sku_count' => 0, 'blocked_sku_count' => 1,
            'summary' => ['v2' => true], 'mismatches' => [],
        ]);
        foreach ($items as $item) {
            $snapshot->rows()->create([
                'provider_sku' => $item['sku'], 'product_id' => $item['product_id'],
                'resolution_status' => $item['status'],
                'oms_total' => $item['qoh'], 'oms_available' => $item['available'],
                'oms_locked' => $item['locked'], 'oms_in_transit' => 0,
            ]);
        }
        return $snapshot;
    }
}
