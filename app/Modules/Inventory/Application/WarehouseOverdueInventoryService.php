<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventoryLot;
use App\Modules\Products\Infrastructure\Models\ProductSkuAlias;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Read-only, per-dated-lot stock aging, capped by current ERP on-hand.
 *
 * In particular, an old sync lot never creates stock that is not on hand.
 * No date is invented for stock without a dated lot or inbound_at field.
 */
final class WarehouseOverdueInventoryService
{
    public const DAYS = 90;

    /** @return array<string,mixed> */
    public function report(?CarbonInterface $asOf = null): array
    {
        // Batch putaway_date is a DATE, not an instant. Never compare a UTC
        // midnight cast to a +08:00 midnight: it shifts the 90-day boundary.
        $today = $asOf
            ? CarbonImmutable::parse($asOf->format('Y-m-d'), 'Asia/Taipei')->startOfDay()
            : CarbonImmutable::today('Asia/Taipei'); // ERP operations day (UTC+8)
        $cutoff = $today->subDays(self::DAYS);

        // Do NOT use is_domestic: factory / 007 have historically been false.
        $warehouses = Warehouse::query()
            ->whereNotNull('country_code')
            ->whereRaw('UPPER(country_code) <> ?', ['CN'])
            ->orderBy('id')
            ->get();

        $warehouseIds = $warehouses->pluck('id')->all();
        $inventories = WarehouseInventory::query()
            ->with('product')
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('quantity_on_hand', '>', 0)
            ->orderBy('warehouse_id')
            ->orderBy('product_id')
            ->get();

        $lots = WarehouseInventoryLot::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('quantity', '>', 0)
            ->get()
            ->groupBy(fn (WarehouseInventoryLot $lot): string => $lot->warehouse_id.':'.$lot->product_id);

        $warehouseById = $warehouses->keyBy('id');
        $providerSkus = $this->verifiedMorePlusSkus($inventories, $warehouses);
        $rows = [];
        $unverifiedUnits = 0;
        $reconciliationCount = 0;
        $reconciliationDeltaUnits = 0;

        foreach ($inventories as $inventory) {
            $warehouse = $warehouseById->get($inventory->warehouse_id);
            if (! $warehouse || ! $inventory->product) {
                continue;
            }

            $qoh = (int) $inventory->quantity_on_hand;
            $key = $inventory->warehouse_id.':'.$inventory->product_id;
            $stockLots = $lots->get($key, collect())->all();

            // A quantity mismatch is a report warning, not permission to rewrite
            // historical FIFO lots. Count at most the current on-hand quantity.
            $rawLotSum = array_sum(array_map(fn ($lot): int => (int) $lot->quantity, $stockLots));
            $lotMismatch = $stockLots !== [] && $rawLotSum !== $qoh;
            if ($lotMismatch) {
                $reconciliationCount++;
                $reconciliationDeltaUnits += abs($qoh - $rawLotSum);
            }

            if ($stockLots === []) {
                $date = $inventory->inbound_at
                    ? CarbonImmutable::parse($inventory->inbound_at->format('Y-m-d'), 'Asia/Taipei')->startOfDay()
                    : null;
                if ($date === null || $date->isAfter($today)) {
                    $unverifiedUnits += $qoh;

                    continue;
                }
                if ($date->lessThanOrEqualTo($cutoff)) {
                    $rows[] = $this->row($warehouse, $inventory, $providerSkus, $date, $today, $qoh,
                        null, 'warehouse_inbound_at', '仅有汇总入库日期，待核对');
                }

                continue;
            }
            // Cap current on-hand quantity from newer to older lots.
            // If historical lots overstate QOH, exclude oldest excess first.
            usort($stockLots, static function (WarehouseInventoryLot $a, WarehouseInventoryLot $b): int {
                $aDate = $a->putaway_date?->format('Y-m-d') ?? '9999-12-31';
                $bDate = $b->putaway_date?->format('Y-m-d') ?? '9999-12-31';

                return strcmp($bDate, $aDate) ?: ((int) $b->id <=> (int) $a->id);
            });

            $remaining = $qoh;
            foreach ($stockLots as $lot) {
                if ($remaining === 0) {
                    break;
                }
                $bounded = min($remaining, (int) $lot->quantity);
                $remaining -= $bounded;

                $date = $lot->putaway_date
                    ? CarbonImmutable::parse($lot->putaway_date->format('Y-m-d'), 'Asia/Taipei')->startOfDay()
                    : null;
                if ($date === null || $date->isAfter($today)) {
                    $unverifiedUnits += $bounded;

                    continue;
                }
                if ($date->lessThanOrEqualTo($cutoff)) {
                    $rows[] = $this->row(
                        $warehouse, $inventory, $providerSkus, $date, $today, $bounded,
                        (int) $lot->id, 'dated_lot',
                        $lotMismatch ? '批次数量与当前库存不符，已按当前库存封顶' : '批次已核对',
                        (string) $lot->stock_status,
                    );
                }
            }
            // QOH above the known lot balance must never inherit an invented date.
            $unverifiedUnits += $remaining;
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['warehouse_code'], $b['warehouse_code'])
            ?: strcmp($a['putaway_date'], $b['putaway_date'])
            ?: strcmp($a['sku'], $b['sku'])
            ?: ($a['lot_id'] <=> $b['lot_id']));

        $uniqueSku = [];
        $uniqueWarehouseSku = [];
        $warehouseUnits = [];
        foreach ($rows as $row) {
            $uniqueSku[$row['product_id']] = true;
            $uniqueWarehouseSku[$row['warehouse_id'].':'.$row['product_id']] = true;
            $warehouseUnits[$row['warehouse_code']] = ($warehouseUnits[$row['warehouse_code']] ?? 0) + $row['quantity'];
        }

        return [
            'as_of' => $today->toDateString(),
            'cutoff_date' => $cutoff->toDateString(),
            'threshold_days' => self::DAYS,
            'summary' => [
                'overdue_units' => array_sum(array_column($rows, 'quantity')),
                'overdue_products' => count($uniqueSku),
                'overdue_warehouse_products' => count($uniqueWarehouseSku),
                'overdue_lot_rows' => count($rows),
                'overdue_warehouses' => count($warehouseUnits),
                'unknown_age_units' => $unverifiedUnits,
                'lot_mismatch_skus' => $reconciliationCount,
                'lot_mismatch_units' => $reconciliationDeltaUnits,
            ],
            'warehouse_units' => $warehouseUnits,
            'warehouses' => $warehouses->map(fn (Warehouse $w): array => [
                'code' => $w->code, 'name' => $w->name,
            ])->all(),
            'rows' => $rows,
        ];
    }

    /** @param array<int,string> $providerSkus
     * @return array<string,mixed>
     */
    private function row(
        Warehouse $warehouse,
        WarehouseInventory $inventory,
        array $providerSkus,
        CarbonImmutable $date,
        CarbonImmutable $today,
        int $quantity,
        ?int $lotId,
        string $source,
        string $quality,
        string $status = 'sellable',
    ): array {
        $ageDays = (int) $date->diffInDays($today);

        return [
            'warehouse_id' => (int) $warehouse->id,
            'warehouse_code' => (string) $warehouse->code,
            'warehouse_name' => (string) $warehouse->name,
            'product_id' => (int) $inventory->product_id,
            'sku' => $providerSkus[(int) $inventory->product_id] ?? (string) $inventory->product->sku,
            'canonical_sku' => (string) $inventory->product->sku,
            'product_name' => (string) $inventory->product->name,
            'putaway_date' => $date->toDateString(),
            'quantity' => $quantity,
            'age_days' => $ageDays,
            'overdue_days' => max(0, $ageDays - self::DAYS),
            'lot_id' => $lotId,
            'stock_status' => $status,
            'source' => $source,
            'quality' => $quality,
        ];
    }

    /** @return array<int,string> */
    private function verifiedMorePlusSkus($inventories, $warehouses): array
    {
        $morePlusIds = $warehouses->filter(
            fn (Warehouse $w): bool => strtoupper((string) $w->code) === 'US-LA'
        )->pluck('id')->all();
        if ($morePlusIds === []) {
            return [];
        }

        $productIds = $inventories->whereIn('warehouse_id', $morePlusIds)
            ->pluck('product_id')->unique()->all();
        if ($productIds === []) {
            return [];
        }

        $aliases = ProductSkuAlias::query()
            ->whereIn('product_id', $productIds)
            ->where('is_active', true)
            ->whereNotNull('oms_verified_at')
            ->whereNotNull('oms_identity_evidence')
            ->get()
            ->groupBy('product_id');

        $result = [];
        foreach ($aliases as $productId => $candidates) {
            if ($candidates->count() === 1 &&
                trim((string) $candidates->first()->oms_identity_evidence) !== '') {
                $result[(int) $productId] = (string) $candidates->first()->alias_sku;
            }
        }

        return $result;
    }
}
