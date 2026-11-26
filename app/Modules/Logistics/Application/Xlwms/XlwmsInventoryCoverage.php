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

        $query = XlwmsInventorySnapshot::query()->where('warehouse_id', $warehouseId);
        $latest = (config('inventory_sku_gate.enabled', false)
                ? $query->orderByDesc('fetched_at')->orderByDesc('id')
                : $query->orderByDesc('id'))->first();
        if ($latest === null) return null;

        if ((bool) ($latest->summary['v2'] ?? false)) {
            $isDePhysical = $latest->wh_code === 'Hannover'
                && ($latest->summary['de_authoritative_physical_v1'] ?? false) === true;
            if ($isDePhysical) {
                // No legacy whole-warehouse proof or unverified SKU can authorize dispatch.
                $ids = array_map('intval', (array) ($latest->summary['de_physical_applied_product_ids'] ?? []));
                if ($ids === [] || count(array_unique($ids)) !== count($ids)
                    || count($ids) !== (int) ($latest->summary['de_authoritative_physical_sku_count'] ?? -1)
                    || $latest->rows()->count() !== (int) $latest->provider_sku_count
                    || $latest->errors || in_array($latest->status, ['failed', 'not_ready'], true)) return [];
                $all = $latest->rows()->whereNotNull('oms_total')->get();
                $excluded = array_fill_keys(array_map(
                    static fn (string $sku): string => mb_strtolower(trim($sku), 'UTF-8'),
                    (array) config('xlwms.de_excluded_inventory_skus', [])
                ), true);
                $all = $all->reject(fn ($row) => isset($excluded[mb_strtolower(trim((string) $row->provider_sku), 'UTF-8')]));
                $available = [];
                $identity = app(\App\Modules\Products\Application\VerifiedOmsProductIdentityResolver::class);
                foreach ($all as $row) {
                    if ($row->oms_available === null || $row->oms_locked === null
                        || (int) $row->oms_total < 0 || (int) $row->oms_available < 0 || (int) $row->oms_locked < 0
                        || (int) $row->oms_total !== (int) $row->oms_available + (int) $row->oms_locked) return [];
                    $id = (int) $row->product_id;
                    if (! in_array($id, $ids, true)) continue;
                    if (! in_array($row->resolution_status, ['matched', 'blocked'], true)
                        || $all->where('product_id', $id)->count() !== 1) continue;
                    $resolved = $identity->resolve((string) $row->provider_sku);
                    if (($resolved['status'] ?? null) !== 'resolved' || (int) $resolved['product_id'] !== $id) continue;
                    $erp = \App\Modules\Inventory\Infrastructure\Models\WarehouseInventory::query()
                        ->where('warehouse_id', $warehouseId)->where('product_id', $id)->first();
                    if (! $erp || $erp->provider_available_quantity === null
                        || (int) $erp->quantity_on_hand !== (int) $row->oms_total
                        || (int) $erp->provider_available_quantity !== (int) $row->oms_available) continue;
                    $available[$id] = (int) $row->oms_available;
                }
                return $available;
            }
            $isolated = (bool) config('inventory_sku_gate.enabled', false);
            if ($isolated) {
                if (! in_array($latest->status, ['applied', 'partial_applied'], true)
                    || ($latest->summary['v2_applied'] ?? false) !== true) return [];
            } elseif ($latest->status !== 'applied') {
                return [];
            }
            // For a partial application, a row is trusted ONLY if its exact
            // product was reconciled and written by the guarded transaction.
            $appliedIds = $latest->status === 'partial_applied'
                ? array_map('intval', (array) ($latest->summary['applied_product_ids'] ?? []))
                : null;
            if ($latest->status === 'partial_applied'
                && ($appliedIds === []
                    || ($latest->summary['sku_isolation_write_proof'] ?? false) !== true)) return [];
            $rows = $latest->rows()->where('resolution_status', 'matched')
                ->whereNotNull('product_id')->get(['product_id', 'oms_total', 'oms_available', 'oms_locked']);
            $available = [];
            foreach ($rows as $row) {
                $id = (int) $row->product_id;
                if ($appliedIds !== null && ! in_array($id, $appliedIds, true)) continue;
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
