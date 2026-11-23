<?php

namespace App\Modules\Sync\Application;

use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsInventorySnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Run-scoped audit of SKU outcomes. Only a guarded, proven inventory apply
 * with a matching ERP readback may be counted as completed.
 * Catalog-only rows are informational, never stock=0 or failed updates.
 */
final class DeTianyunInventoryRunReporter
{
    /** @return array<string,int|string> */
    public function record(int $runId, array $result): array
    {
        return DB::transaction(function () use ($runId, $result): array {
            $run = DB::table('xlwms_inventory_manual_runs')->where('id', $runId)->lockForUpdate()->first();
            if (! $run || $run->status !== 'running') {
                throw new RuntimeException('XLWMS_DE_RUN_NOT_RUNNING');
            }
            $snapshot = XlwmsInventorySnapshot::query()
                ->where('warehouse_id', $run->warehouse_id)
                ->find($result['snapshot_id'] ?? 0);
            if (! $snapshot) {
                return $this->finish($runId, [
                    'outcome' => 'failed', 'successful_sku_count' => 0,
                    'updated_sku_count' => 0, 'unchanged_sku_count' => 0,
                    'failed_sku_count' => 0, 'missing_sku_count' => 0,
                ]);
            }
            $summary = (array) ($snapshot->summary ?? []);
            $proven = (bool) ($run->apply_requested
                && in_array($snapshot->status, ['applied', 'partial_applied'], true)
                && ($summary['sku_isolation_write_proof'] ?? false) === true
                && ($summary['v2_applied'] ?? false) === true);
            $appliedIds = array_fill_keys(array_map('intval',
                (array) ($summary['applied_product_ids'] ?? [])), true);
            $changes = collect((array) ($summary['changes'] ?? []))->keyBy('sku');
            $issues = collect((array) ($snapshot->mismatches ?? []));
            $writtenDuringRun = $proven && $snapshot->applied_at !== null
                && $run->started_at !== null
                && Carbon::parse($snapshot->applied_at)->greaterThanOrEqualTo(Carbon::parse($run->started_at));
            $priorVerified = $run->resume_from_run_id === null ? collect()
                : DB::table('xlwms_inventory_manual_run_rows')
                    ->where('run_id', $run->resume_from_run_id)
                    ->where('write_verified', true)
                    ->whereIn('outcome', ['updated', 'unchanged'])
                    ->get(['provider_sku', 'product_id', 'oms_quantity'])
                    ->keyBy('provider_sku');
            $counts = ['updated' => 0, 'unchanged' => 0, 'unmatched' => 0,
                'blocked' => 0, 'missing' => 0, 'preview' => 0];
            foreach ($snapshot->rows()->orderBy('provider_sku')->get() as $row) {
                $sku = (string) $row->provider_sku;
                $productId = $row->product_id === null ? null : (int) $row->product_id;
                $qoh = $row->oms_total === null ? null : (int) $row->oms_total;
                $current = $productId === null ? null : WarehouseInventory::query()
                    ->where('warehouse_id', $run->warehouse_id)
                    ->where('product_id', $productId)->first();
                $after = $current === null ? null : (int) $current->quantity_on_hand;
                $change = $changes->get($sku);
                $before = $change === null
                    ? $after : (isset($change['erp']) ? (int) $change['erp'] : null);
                $issue = $issues->first(fn ($item): bool => is_array($item)
                    && (($item['sku'] ?? null) === $sku
                        || ($productId !== null && (int) ($item['product_id'] ?? 0) === $productId)));
                $reason = $row->mismatch_reason ?? ($issue['reason'] ?? null);
                $prior = $priorVerified->get($sku);
                $carriedForward = (bool) $run->apply_requested && $prior !== null && $productId !== null
                    && (int) $prior->product_id === $productId
                    && (int) $prior->oms_quantity === $qoh && $after === $qoh
                    && $row->resolution_status === 'matched' && $reason === null;
                $verified = ($proven && $productId !== null
                    && isset($appliedIds[$productId]) && $qoh !== null && $after === $qoh
                    && $row->resolution_status === 'matched' && $reason === null)
                    || $carriedForward;
                if ($row->resolution_status === 'catalog_only') {
                    $status = 'missing';
                } elseif ($row->resolution_status === 'unmatched') {
                    $status = 'unmatched';
                } elseif ($row->resolution_status === 'blocked' || $reason !== null) {
                    $status = 'blocked';
                } elseif ($verified) {
                    $status = ! $carriedForward && $writtenDuringRun && $before !== $after
                        ? 'updated' : 'unchanged';
                } else {
                    $status = $run->apply_requested ? 'blocked' : 'preview';
                    $reason ??= $run->apply_requested
                        ? 'XLWMS_DE_WRITE_PROOF_OR_READBACK_MISSING' : null;
                }
                $counts[$status]++;
                DB::table('xlwms_inventory_manual_run_rows')->insert([
                    'run_id' => $runId, 'provider_sku' => $sku, 'product_id' => $productId,
                    'outcome' => $status, 'oms_quantity' => $qoh, 'erp_before' => $before,
                    'erp_after' => $after, 'delta' => ($before === null || $qoh === null) ? null : $qoh - $before,
                    'error_code' => $reason, 'write_verified' => $verified,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $successful = $counts['updated'] + $counts['unchanged'];
            $pending = $counts['unmatched'] + $counts['blocked'];
            $outcome = ! $run->apply_requested ? 'preview'
                : ($successful > 0 && $pending === 0 ? 'success'
                    : ($successful > 0 ? 'partial_success' : 'failed'));
            return $this->finish($runId, [
                'outcome' => $outcome, 'successful_sku_count' => $successful,
                'updated_sku_count' => $counts['updated'],
                'unchanged_sku_count' => $counts['unchanged'],
                'failed_sku_count' => $pending, 'missing_sku_count' => $counts['missing'],
            ]);
        });
    }

    /** @param array<string,int|string> $stats */
    private function finish(int $runId, array $stats): array
    {
        DB::table('xlwms_inventory_manual_runs')->where('id', $runId)->update($stats);
        return $stats;
    }
}
