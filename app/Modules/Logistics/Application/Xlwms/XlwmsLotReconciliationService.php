<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventoryLot;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsInventoryLotReconciliation;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsInventoryUnverifiedAge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class XlwmsLotReconciliationService
{
    /** @param list<array<string,mixed>> $rows @return array{blocked:list<array<string,mixed>>, products:array<int,array<string,mixed>>, summary:array<string,mixed>} */
    public function plan(Warehouse $warehouse, array $rows, array $baseline = []): array
    {
        if (! Schema::hasTable('xlwms_inventory_unverified_ages') || ! Schema::hasTable('xlwms_inventory_lot_reconciliations')) {
            throw new RuntimeException('XLWMS_LOT_RECONCILIATION_V2_SCHEMA_MISSING');
        }

        $matched = collect($rows)->filter(fn (array $row): bool => $row['status'] === 'matched' && isset($row['stock']['total'], $row['product_id']))->values();
        $ids = $matched->pluck('product_id')->map(fn ($id): int => (int) $id)->unique()->values();
        Warehouse::query()->whereKey($warehouse->id)->lockForUpdate()->firstOrFail();
        $inventories = WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->whereIn('product_id', $ids)->lockForUpdate()->get()->keyBy('product_id');
        $lots = WarehouseInventoryLot::query()->where('warehouse_id', $warehouse->id)->whereIn('product_id', $ids)->orderBy('putaway_date')->orderBy('id')->lockForUpdate()->get()->groupBy('product_id');
        $unknown = XlwmsInventoryUnverifiedAge::query()->where('warehouse_id', $warehouse->id)->whereIn('product_id', $ids)->lockForUpdate()->get()->keyBy('product_id');
        $blocked = [];
        $products = [];

        foreach ($matched as $row) {
            $productId = (int) $row['product_id'];
            $inventory = $inventories->get($productId);
            $provider = (int) $row['stock']['total'];
            $reserved = (int) ($inventory?->quantity_reserved ?? 0);
            $locked = (int) ($row['stock']['locked'] ?? 0);
            $productLots = $lots->get($productId, collect());
            $dated = $productLots->filter(fn (WarehouseInventoryLot $lot): bool => (string) $lot->stock_status === 'sellable' && $lot->putaway_date !== null && (int) $lot->quantity >= 0);
            $datedBefore = (int) $dated->sum('quantity');
            $targetDated = min($provider, $datedBefore);
            $unknownBefore = (int) ($unknown->get($productId)?->unknown_age_quantity ?? 0);
            $unknownAfter = $provider - $targetDated;
            $reductions = [];
            $remainingReduction = max($datedBefore - $targetDated, 0);
            foreach ($dated as $lot) {
                $reduce = min((int) $lot->quantity, $remainingReduction);
                $remainingReduction -= $reduce;
                $reductions[] = ['lot_id' => (int) $lot->id, 'putaway_date' => $lot->putaway_date->toDateString(), 'before' => (int) $lot->quantity, 'after' => (int) $lot->quantity - $reduce, 'reduction' => $reduce, 'sync_batch_id' => $lot->sync_batch_id];
            }
            $adjustingLots = collect($reductions)->contains(fn (array $lot): bool => $lot['reduction'] > 0);
            $reason = null;
            if (! $inventory) $reason = 'ERP_INVENTORY_ROW_MISSING';
            elseif (isset($baseline[$row['sku']]) && ((int) ($baseline[$row['sku']]['product_id'] ?? 0) !== $productId || $baseline[$row['sku']]['qoh'] !== $inventory->quantity_on_hand || $baseline[$row['sku']]['reserved'] !== $inventory->quantity_reserved || ($baseline[$row['sku']]['reason'] ?? null) !== ($row['reason'] ?? null))) $reason = 'ERP_LOCAL_BASELINE_CHANGED';
            elseif ($locked > $reserved || $reserved > $provider) $reason = 'RESERVATION_PROVIDER_CONFLICT';
            $changingPhysicalOrLot = (int) ($inventory?->quantity_on_hand ?? 0) !== $provider
                || $unknownBefore !== $unknownAfter || $adjustingLots;
            if ($reason === null && $changingPhysicalOrLot) {
                if (DB::table('warehouse_inventory_lot_consumptions')->where('warehouse_id', $warehouse->id)->where('product_id', $productId)->exists()) $reason = 'LOT_CONSUMPTION_EXISTS';
                elseif (DB::table('warehouse_storage_aging_accruals')->where('warehouse_id', $warehouse->id)->where('product_id', $productId)->exists()) $reason = 'LOT_AGING_ACCRUAL_EXISTS';
                elseif ($adjustingLots && $dated->contains(fn (WarehouseInventoryLot $lot): bool => $lot->sync_batch_id !== null)) $reason = 'LOT_SOURCE_BATCH_LINKED';
            }
            if ($reason === null && $productLots->contains(fn (WarehouseInventoryLot $lot): bool =>
                (string) $lot->stock_status === 'sellable' && (int) $lot->quantity > 0 && $lot->putaway_date === null)) {
                $reason = 'LEGACY_SELLABLE_LOT_DATE_MISSING';
            }
            if ($reason !== null) $blocked[] = ['sku' => $row['sku'], 'product_id' => $productId, 'reason' => $reason];
            $products[$productId] = ['sku' => $row['sku'], 'product_id' => $productId, 'provider_total' => $provider, 'qoh_before' => (int) ($inventory?->quantity_on_hand ?? 0), 'qoh_after' => $provider, 'dated_before' => $datedBefore, 'dated_after' => $targetDated, 'unknown_before' => $unknownBefore, 'unknown_after' => $unknownAfter, 'physical_legacy_mismatch' => ['qoh_before' => (int) ($inventory?->quantity_on_hand ?? 0), 'dated_lot_quantity_before' => $datedBefore, 'unknown_age_before' => $unknownBefore, 'provider_total' => $provider], 'lot_adjustments' => $reductions, 'blocked' => $reason];
        }

        return ['blocked' => $blocked, 'products' => $products, 'summary' => ['v2' => true, 'dated_lot_reductions' => collect($products)->flatMap(fn (array $product): array => array_values(array_filter($product['lot_adjustments'], fn (array $lot): bool => $lot['reduction'] > 0)))->values()->all(), 'unknown_age' => collect($products)->mapWithKeys(fn (array $product): array => [$product['sku'] => $product['unknown_after']])->all(), 'blockers' => $blocked]];
    }

    /** @param array{products:array<int,array<string,mixed>>} $plan */
    public function apply(Warehouse $warehouse, array $plan, int $snapshotId, string $checksum): void
    {
        if ($plan['blocked'] !== []) return;
        foreach ($plan['products'] as $product) {
            $inventory = WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product['product_id'])->lockForUpdate()->firstOrFail();
            $inventory->forceFill(['quantity_on_hand' => $product['qoh_after']])->save();
            foreach ($product['lot_adjustments'] as $adjustment) {
                if ($adjustment['reduction'] <= 0) continue;
                WarehouseInventoryLot::query()->whereKey($adjustment['lot_id'])->lockForUpdate()->update(['quantity' => $adjustment['after']]);
            }
            XlwmsInventoryUnverifiedAge::query()->updateOrCreate(['warehouse_id' => $warehouse->id, 'product_id' => $product['product_id']], ['unknown_age_quantity' => $product['unknown_after'], 'last_snapshot_id' => $snapshotId]);
            XlwmsInventoryLotReconciliation::query()->updateOrCreate(['snapshot_id' => $snapshotId, 'product_id' => $product['product_id']], ['warehouse_id' => $warehouse->id, 'qoh_before' => $product['qoh_before'], 'qoh_after' => $product['qoh_after'], 'dated_lot_quantity_before' => $product['dated_before'], 'dated_lot_quantity_after' => $product['dated_after'], 'unknown_age_before' => $product['unknown_before'], 'unknown_age_after' => $product['unknown_after'], 'physical_legacy_mismatch' => $product['physical_legacy_mismatch'], 'lot_adjustments' => $product['lot_adjustments'], 'source_checksum' => $checksum]);
        }
    }
}
