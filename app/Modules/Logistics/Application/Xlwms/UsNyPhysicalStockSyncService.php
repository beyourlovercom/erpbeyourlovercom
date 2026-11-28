<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsInventorySnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** US01 physical stock mirror; never rewrites reservations or historical lots. */
final class UsNyPhysicalStockSyncService
{
    public function __construct(private readonly XlwmsInventorySyncService $source) {}

    public function run(bool $apply = false): array
    {
        $this->assertApply($apply);
        $lock = Cache::lock('xlwms:us-ny:physical-stock', 600);
        if (! $lock->get()) {
            throw new RuntimeException('US_NY_SYNC_ALREADY_RUNNING');
        }
        try {
            $fresh = $this->source->sync(false, 'US-NY');

            return $this->reconcileSnapshot((int) ($fresh['snapshot_id'] ?? 0), $apply);
        } catch (\Throwable $e) {
            // Invalid/incomplete source cannot authorize new allocations; preserve actual counts and reservations.
            if ($apply) {
                WarehouseInventory::query()->whereHas('warehouse', fn ($q) => $q->where('code', 'US-NY'))
                    ->update(['provider_stock_synced_at' => null]);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public function reconcileSnapshot(int $id, bool $apply = false): array
    {
        $this->assertApply($apply);

        return DB::transaction(function () use ($id, $apply): array {
            $warehouse = Warehouse::query()->where('code', 'US-NY')->where('is_active', true)->lockForUpdate()->firstOrFail();
            $snapshot = XlwmsInventorySnapshot::query()->where('warehouse_id', $warehouse->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            $latest = XlwmsInventorySnapshot::query()->where('warehouse_id', $warehouse->id)->orderByDesc('fetched_at')->orderByDesc('id')->first();
            if ((int) $latest?->id !== $id || $snapshot->wh_code !== 'US01' || ! $snapshot->fetched_at
                || $snapshot->fetched_at->isFuture() || $snapshot->fetched_at->diffInSeconds(now()) > 150
                || $snapshot->errors || in_array($snapshot->status, ['failed', 'not_ready'], true)
                || ($snapshot->summary['v2'] ?? false) !== true) {
                throw new RuntimeException('US_NY_UNTRUSTED_SNAPSHOT');
            }
            $rows = $snapshot->rows()->get();
            if ($rows->count() !== (int) $snapshot->provider_sku_count || $rows->isEmpty()) {
                throw new RuntimeException('US_NY_INCOMPLETE_COVERAGE');
            }
            $stock = $rows->reject(fn ($r) => $r->resolution_status === 'catalog_only' && $r->oms_total === null);
            $plan = [];
            $quarantine = [];
            foreach ($stock as $row) {
                if ($row->oms_total === null || $row->oms_available === null || $row->oms_locked === null
                    || min((int) $row->oms_total, (int) $row->oms_available, (int) $row->oms_locked) < 0
                    || (int) $row->oms_total !== (int) $row->oms_available + (int) $row->oms_locked) {
                    throw new RuntimeException('US_NY_INVALID_QUANTITY');
                }
                $pid = (int) $row->product_id;
                $identity = $this->source->resolveWarehouseSku($warehouse, (string) $row->provider_sku);
                if ($pid <= 0 || $row->resolution_status !== 'matched'
                    || ($identity['status'] ?? null) !== 'resolved' || (int) $identity['product_id'] !== $pid
                    || $stock->where('product_id', $pid)->count() !== 1) {
                    $quarantine[] = ['sku' => $row->provider_sku, 'qty' => (int) $row->oms_total];

                    continue;
                }
                $existing = WarehouseInventory::withTrashed()->where('warehouse_id', $warehouse->id)->where('product_id', $pid)->lockForUpdate()->first();
                if ($existing?->trashed()) {
                    $quarantine[] = ['sku' => $row->provider_sku, 'qty' => (int) $row->oms_total];

                    continue;
                }
                $plan[$pid] = [$row, $existing];
            }
            $changes = 0;
            foreach ($plan as $pid => [$row, $inventory]) {
                $qoh = (int) $row->oms_total;
                $free = (int) $row->oms_available;
                $before = $inventory?->quantity_on_hand;
                $reserved = (int) ($inventory?->quantity_reserved ?? 0);
                $changed = ! $inventory || $before !== $qoh || $inventory->provider_available_quantity !== $free;
                $changes += (int) $changed;
                if (! $apply) {
                    continue;
                }
                $inventory ??= new WarehouseInventory(['warehouse_id' => $warehouse->id, 'product_id' => $pid, 'quantity_reserved' => 0]);
                $inventory->forceFill(['quantity_on_hand' => $qoh, 'provider_available_quantity' => $free,
                    'provider_stock_synced_at' => $snapshot->fetched_at])->save();
                if ($changed) {
                    DB::table('inventory_adjustments')->insert([
                        'warehouse_id' => $warehouse->id, 'product_id' => $pid, 'warehouse_inventory_id' => $inventory->id,
                        'quantity_before' => $before ?? 0, 'quantity_after' => $qoh, 'reserved_before' => $reserved,
                        'reserved_after' => $reserved, 'actor_user_id' => null, 'reason' => 'US-NY OMS physical stock snapshot #'.$id,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            if ($apply) {
                // Unverified or omitted local SKU: keep physical history, revoke current allocation proof.
                WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->whereNotIn('product_id', array_keys($plan))
                    ->update(['provider_stock_synced_at' => null]);
                $summary = (array) $snapshot->summary;
                $summary['us_ny_physical_applied_product_ids'] = array_keys($plan);
                $summary['us_ny_physical_applied_at'] = now()->toIso8601String();
                $summary['us_ny_physical_quarantine'] = $quarantine;
                $snapshot->forceFill(['summary' => $summary])->save();
            }

            return ['warehouse' => 'US-NY', 'snapshot_id' => $id, 'mode' => $apply ? 'apply' : 'dry_run',
                'verified_skus' => count($plan), 'changed_skus' => $changes, 'quarantined' => $quarantine,
                'oms_verified_total' => array_sum(array_map(fn ($x) => (int) $x[0]->oms_total, $plan))];
        }, 3);
    }

    private function assertApply(bool $apply): void
    {
        if ($apply && ! config('xlwms.us_ny_inventory_physical_apply_enabled', false)) {
            throw new RuntimeException('US_NY_PHYSICAL_APPLY_DISABLED');
        }
    }
}
