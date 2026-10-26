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
        $limits = $this->providerAvailableByProductId($warehouseId);
        return $limits === null ? null : array_keys($limits);
    }

    /**
     * Last authoritative V2 applied snapshot's provider *available* units,
     * excluding stock the OMS already locked for pending outbound shipment.
     * null = V2 not activated; [] = no confirmed current V2 source, fail closed.
     *
     * @return array<int,int>|null product_id => remotely available quantity
     */
    public function providerAvailableByProductId(int $warehouseId): ?array
    {
        if (! Schema::hasTable('xlwms_inventory_snapshots')) return null;

        $latest = XlwmsInventorySnapshot::query()->where('warehouse_id', $warehouseId)
            ->orderByDesc('id')->first();
        if ($latest === null) return null;

        if ((bool) ($latest->summary['v2'] ?? false)) {
            if ($latest->status !== 'applied') return [];
            $rows = $latest->rows()->where('resolution_status', 'matched')
                ->whereNotNull('product_id')->get(['product_id', 'oms_total', 'oms_available', 'oms_locked']);
            $available = [];
            foreach ($rows as $row) {
                $id = (int) $row->product_id;
                $total = $row->oms_total;
                $free = $row->oms_available;
                $locked = $row->oms_locked;
                // Missing/corrupt provider quantities may never authorize stock.
                if ($id <= 0 || isset($available[$id]) || $total === null || $free === null || $locked === null
                    || (int) $free < 0 || (int) $locked < 0 || (int) $total !== (int) $free + (int) $locked) {
                    return [];
                }
                $available[$id] = (int) $free;
            }
            return $available;
        }

        if (Schema::hasTable('xlwms_inventory_lot_reconciliations')
            && DB::table('xlwms_inventory_lot_reconciliations')
                ->where('warehouse_id', $warehouseId)->exists()) return [];
        return null;
    }
}
