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
        // P9 FIFO records consumption separately; the source lot remains GROSS.
        // Lock and inspect the underlying ledger rather than double-deducting shipments.
        $consumptions = DB::table('warehouse_inventory_lot_consumptions')->where('warehouse_id', $warehouse->id)
            ->whereIn('product_id', $ids)->lockForUpdate()->get()->groupBy('product_id');
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
            $unknownBefore = (int) ($unknown->get($productId)?->unknown_age_quantity ?? 0);
            $productConsumptions = $consumptions->get($productId, collect());
            $hasConsumptions = $productConsumptions->isNotEmpty();
            $netDated = $datedBefore;
            $consumptionReason = null;
            if ($hasConsumptions) {
                $lotsById = $dated->keyBy('id');
                $consumedByLot = [];
                foreach ($productConsumptions as $consumption) {
                    $sourceLotId = (int) ($consumption->source_inventory_lot_id ?? 0);
                    $source = $lotsById->get($sourceLotId);
                    // Unknown/batch-linked/undated sources cannot be safely
                    // reinterpreted as direct P9 consumption of these lots.
                    if (! $source || $source->sync_batch_id !== null || $consumption->source_sync_batch_id !== null
                        || (string) $consumption->stock_status !== 'sellable'
                        || substr((string) $consumption->putaway_date, 0, 10) !== $source->putaway_date->toDateString()
                        || (int) $consumption->quantity <= 0) {
                        $consumptionReason = 'LOT_CONSUMPTION_SOURCE_UNVERIFIED';
                        break;
                    }
                    $consumedByLot[$sourceLotId] = ($consumedByLot[$sourceLotId] ?? 0) + (int) $consumption->quantity;
                }
                if ($consumptionReason === null) {
                    foreach ($dated as $source) {
                        if ((int) ($consumedByLot[(int) $source->id] ?? 0) > (int) $source->quantity) {
                            $consumptionReason = 'LOT_CONSUMPTION_EXCEEDS_SOURCE_LOT';
                            break;
                        }
                    }
                }
                $netDated = $datedBefore - array_sum($consumedByLot);
                if ($consumptionReason === null && ($netDated !== $provider || $unknownBefore !== 0)) {
                    $consumptionReason = 'LOT_NET_PROVIDER_MISMATCH';
                }
            }
            // Existing historical consumption must never be subtracted twice.
            // A verified net match retains the original gross batch unchanged.
            $targetDated = $hasConsumptions ? min($provider, max($netDated, 0)) : min($provider, $datedBefore);
            $unknownAfter = $provider - $targetDated;
            $reductions = [];
            $remainingReduction = $hasConsumptions ? 0 : max($datedBefore - $targetDated, 0);
            foreach ($dated as $lot) {
                $reduce = min((int) $lot->quantity, $remainingReduction);
                $remainingReduction -= $reduce;
                $reductions[] = ['lot_id' => (int) $lot->id, 'putaway_date' => $lot->putaway_date->toDateString(), 'before' => (int) $lot->quantity, 'after' => (int) $lot->quantity - $reduce, 'reduction' => $reduce, 'sync_batch_id' => $lot->sync_batch_id];
            }
            $adjustingLots = collect($reductions)->contains(fn (array $lot): bool => $lot['reduction'] > 0);
            // Canonical OMS zero, no ERP row or protected evidence: safe to
            // establish a ZERO baseline only after the normal apply gates pass.
            $zeroBaselineSafe = ! $inventory && $provider === 0 && $productLots->isEmpty()
                && ! $hasConsumptions && ! $unknown->has($productId)
                && ! WarehouseInventory::withTrashed()->where('warehouse_id', $warehouse->id)
                    ->where('product_id', $productId)->exists()
                && ! DB::table('warehouse_storage_aging_accruals')->where('warehouse_id', $warehouse->id)
                    ->where('product_id', $productId)->exists();
            $reason = null;
            if (! $inventory && ! $zeroBaselineSafe) $reason = 'ERP_INVENTORY_ROW_MISSING';
            elseif ($inventory && isset($baseline[$row['sku']]) && ((int) ($baseline[$row['sku']]['product_id'] ?? 0) !== $productId || $baseline[$row['sku']]['qoh'] !== $inventory->quantity_on_hand || $baseline[$row['sku']]['reserved'] !== $inventory->quantity_reserved || ($baseline[$row['sku']]['reason'] ?? null) !== ($row['reason'] ?? null))) $reason = 'ERP_LOCAL_BASELINE_CHANGED';
            elseif ($reserved > $provider) $reason = 'ERP_RESERVATIONS_EXCEED_PROVIDER_PHYSICAL_QOH';
            // Provider-locked units are confirmed upcoming OMS outbound stock,
            // distinct from ERP's own reservation ledger. This is normal even
            // when local ERP reservation == 0; never synthesize local holds here.
            $changingPhysicalOrLot = (int) ($inventory?->quantity_on_hand ?? 0) !== $provider
                || $unknownBefore !== $unknownAfter || $adjustingLots;
            if ($reason === null && $consumptionReason !== null) $reason = $consumptionReason;
            if ($reason === null && $changingPhysicalOrLot) {
                if (DB::table('warehouse_storage_aging_accruals')->where('warehouse_id', $warehouse->id)->where('product_id', $productId)->exists()) $reason = 'LOT_AGING_ACCRUAL_EXISTS';
                elseif ($adjustingLots && $dated->contains(fn (WarehouseInventoryLot $lot): bool => $lot->sync_batch_id !== null)) $reason = 'LOT_SOURCE_BATCH_LINKED';
            }
            if ($reason === null && $productLots->contains(fn (WarehouseInventoryLot $lot): bool =>
                (string) $lot->stock_status === 'sellable' && (int) $lot->quantity > 0 && $lot->putaway_date === null)) {
                $reason = 'LEGACY_SELLABLE_LOT_DATE_MISSING';
            }
            if ($reason !== null) {
                $detail = ['sku' => $row['sku'], 'product_id' => $productId, 'reason' => $reason];
                if ($reason === 'LOT_NET_PROVIDER_MISMATCH') {
                    $detail += ['gross_dated' => $datedBefore, 'consumed' => $datedBefore - $netDated,
                        'net_dated' => $netDated, 'provider_total' => $provider, 'unverified_age' => $unknownBefore];
                }
                $blocked[] = $detail;
            }
            $products[$productId] = ['sku' => $row['sku'], 'product_id' => $productId, 'provider_total' => $provider, 'qoh_before' => (int) ($inventory?->quantity_on_hand ?? 0), 'qoh_after' => $provider, 'dated_before' => $datedBefore, 'dated_after' => $targetDated, 'unknown_before' => $unknownBefore, 'unknown_after' => $unknownAfter, 'zero_baseline_safe' => $zeroBaselineSafe, 'net_dated' => $netDated, 'p9_consumed_quantity' => $datedBefore - $netDated, 'physical_legacy_mismatch' => ['qoh_before' => (int) ($inventory?->quantity_on_hand ?? 0), 'dated_lot_quantity_before' => $datedBefore, 'unknown_age_before' => $unknownBefore, 'provider_total' => $provider], 'lot_adjustments' => $reductions, 'blocked' => $reason];
        }

        return ['blocked' => $blocked, 'products' => $products, 'summary' => ['v2' => true, 'dated_lot_reductions' => collect($products)->flatMap(fn (array $product): array => array_values(array_filter($product['lot_adjustments'], fn (array $lot): bool => $lot['reduction'] > 0)))->values()->all(), 'unknown_age' => collect($products)->mapWithKeys(fn (array $product): array => [$product['sku'] => $product['unknown_after']])->all(), 'blockers' => $blocked]];
    }

    /** @param array{products:array<int,array<string,mixed>>} $plan */
    public function apply(Warehouse $warehouse, array $plan, int $snapshotId, string $checksum): void
    {
        if ($plan['blocked'] !== []) return;
        foreach ($plan['products'] as $product) {
            $inventory = WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product['product_id'])->lockForUpdate()->first();
            if ($inventory === null) {
                if (! ($product['zero_baseline_safe'] ?? false) || (int) $product['qoh_after'] !== 0
                    || (int) $product['provider_total'] !== 0) {
                    throw new RuntimeException('XLWMS_V2_UNSAFE_MISSING_INVENTORY_ROW');
                }
                $inventory = WarehouseInventory::query()->create([
                    'warehouse_id' => $warehouse->id, 'product_id' => $product['product_id'],
                    'quantity_on_hand' => 0, 'quantity_reserved' => 0,
                ]);
            } else {
                $inventory->forceFill(['quantity_on_hand' => $product['qoh_after']])->save();
            }
            foreach ($product['lot_adjustments'] as $adjustment) {
                if ($adjustment['reduction'] <= 0) continue;
                WarehouseInventoryLot::query()->whereKey($adjustment['lot_id'])->lockForUpdate()->update(['quantity' => $adjustment['after']]);
            }
            XlwmsInventoryUnverifiedAge::query()->updateOrCreate(['warehouse_id' => $warehouse->id, 'product_id' => $product['product_id']], ['unknown_age_quantity' => $product['unknown_after'], 'last_snapshot_id' => $snapshotId]);
            XlwmsInventoryLotReconciliation::query()->updateOrCreate(['snapshot_id' => $snapshotId, 'product_id' => $product['product_id']], ['warehouse_id' => $warehouse->id, 'qoh_before' => $product['qoh_before'], 'qoh_after' => $product['qoh_after'], 'dated_lot_quantity_before' => $product['dated_before'], 'dated_lot_quantity_after' => $product['dated_after'], 'unknown_age_before' => $product['unknown_before'], 'unknown_age_after' => $product['unknown_after'], 'physical_legacy_mismatch' => $product['physical_legacy_mismatch'], 'lot_adjustments' => $product['lot_adjustments'], 'source_checksum' => $checksum]);
        }
    }
}
