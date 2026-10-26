<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsInventorySnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Null = V2 not activated; [] = V2 unsafe/blocked; IDs = confirmed OMS-covered SKUs. */
final class XlwmsInventoryCoverage
{
    /** @return list<int>|null */
    public function coveredProductIds(int $warehouseId): ?array
    {
        if (! Schema::hasTable('xlwms_inventory_snapshots')) return null;

        $latest = XlwmsInventorySnapshot::query()->where('warehouse_id', $warehouseId)
            ->orderByDesc('id')->first();
        if ($latest === null) return null;

        if ((bool) ($latest->summary['v2'] ?? false)) {
            if ($latest->status !== 'applied') return []; // latest read is unconfirmed
            return $latest->rows()->where('resolution_status', 'matched')
                ->whereNotNull('product_id')->pluck('product_id')
                ->map(fn ($id): int => (int) $id)->unique()->values()->all();
        }

        // Once reconciled with V2, an older-mode/unknown newest snapshot cannot
        // silently make unobserved products allocatable again.
        if (Schema::hasTable('xlwms_inventory_lot_reconciliations')
            && DB::table('xlwms_inventory_lot_reconciliations')
                ->where('warehouse_id', $warehouseId)->exists()) return [];

        return null;
    }
}
