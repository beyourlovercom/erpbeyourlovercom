<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsInventorySnapshot;

/**
 * Prevents an unreviewed initial write and stops runaway recurring inventory
 * adjustments. Operates only on verified, warehouse-scoped V2 stock rows.
 */
final class XlwmsInventoryApplyGuard
{
    /** @param array<string,mixed> $plan
     *  @param list<array<string,mixed>> $rows
     *  @return list<array<string,mixed>>
     */
    public function blockers(Warehouse $warehouse, array $plan, array $rows, string $checksum): array
    {
        $last = XlwmsInventorySnapshot::query()->where('warehouse_id', $warehouse->id)
            ->where('status', 'applied')->orderByDesc('id')->first();

        if ($last === null) {
            $approved = trim((string) config('xlwms.inventory_first_apply_approved_checksum', ''));
            if (strlen($approved) !== 64 || ! ctype_xdigit($approved)
                || ! hash_equals(strtolower($approved), strtolower($checksum))) {
                return [['reason' => 'FIRST_APPLY_EXACT_SNAPSHOT_APPROVAL_REQUIRED']];
            }
            return [];
        }

        // An older V1 apply is not a V2 acceptance and cannot bootstrap automatic
        // V2 writes with no explicit first V2 owner-reviewed snapshot.
        if (! (bool) ($last->summary['v2'] ?? false)) {
            return [['reason' => 'FIRST_V2_APPLY_EXACT_SNAPSHOT_APPROVAL_REQUIRED']];
        }

        $prior = $last->rows()->where('resolution_status', 'matched')
            ->whereNotNull('product_id')->get(['provider_sku', 'product_id'])
            ->mapWithKeys(fn ($r): array => [$r->provider_sku => (int) $r->product_id])->sortKeys()->all();
        $current = collect($rows)->filter(fn (array $row): bool =>
            ($row['status'] ?? null) === 'matched' && isset($row['product_id'], $row['stock']['total']))
            ->mapWithKeys(fn (array $row): array => [$row['sku'] => (int) $row['product_id']])
            ->sortKeys()->all();

        if ($prior !== $current) {
            return [['reason' => 'OMS_STOCK_SKU_COVERAGE_OR_IDENTITY_CHANGED']];
        }

        $skuLimit = max(0, (int) config('xlwms.inventory_auto_max_sku_delta_units', 5));
        $totalLimit = max(0, (int) config('xlwms.inventory_auto_max_total_absolute_delta_units', 15));
        $totalAbs = 0;
        $blockers = [];
        foreach ($plan['products'] as $p) {
            $difference = abs((int) $p['qoh_after'] - (int) $p['qoh_before']);
            $totalAbs += $difference;
            if ($difference > $skuLimit) {
                $blockers[] = ['reason' => 'OMS_SKU_DELTA_EXCEEDS_AUTO_LIMIT', 'sku' => $p['sku'],
                    'absolute_delta' => $difference, 'limit' => $skuLimit];
            }
        }
        if ($totalAbs > $totalLimit) {
            $blockers[] = ['reason' => 'OMS_TOTAL_DELTA_EXCEEDS_AUTO_LIMIT',
                'absolute_delta' => $totalAbs, 'limit' => $totalLimit];
        }
        return $blockers;
    }
}
