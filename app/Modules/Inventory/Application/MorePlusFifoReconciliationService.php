<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventoryLot;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventoryLotConsumption;
use App\Modules\Inventory\Infrastructure\Models\WarehouseStorageAgingAccrual;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Separate opt-in for provider-authoritative physical shortages on dated FIFO
 * lots. Never claims a provider hold, creates an arrival date or rewrites P9.
 */
final class MorePlusFifoReconciliationService
{
    public function __construct(
        private readonly MorePlusInventorySyncService $stock,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array<string,mixed> */
    public function preview(Warehouse $warehouse): array
    {
        return $this->previewFromStock($warehouse, $this->stock->preview($warehouse));
    }

    /** Reuse the exact same signed GET as the general stock preview. */
    public function previewFromStock(Warehouse $warehouse, array $stock): array
    {
        if (strtoupper((string) $warehouse->code) !== 'US-LA'
            || $stock['warehouse_code'] !== 'US-LA'
            || $stock['source_sku_count'] !== count($stock['rows'])) {
            throw new RuntimeException('MOREPLUS_FIFO_SOURCE_IDENTITY_UNBOUND');
        }
        $rows = [];
        foreach ($stock['rows'] as $row) {
            $financialReason = $row['accounting_warning'] ?? $row['reason'] ?? null;
            if ($financialReason === null) {
                continue;
            }
            // Accounting review is separate from the provider-authoritative stock mirror.
            $rows[] = $this->inspect($warehouse, [...$row, 'reason' => $financialReason]);
        }
        $ready = array_values(array_filter($rows, fn (array $r): bool => $r['status'] === 'ready_fifo'));
        $blocked = array_values(array_filter($rows, fn (array $r): bool => $r['status'] === 'blocked'));
        $allowed = (array) config('shipout.production_fifo_approved_skus', []);
        $maxUnits = (int) config('shipout.production_fifo_max_units', 0);
        $inApprovedScope = $ready !== [] && $allowed !== [] && $maxUnits > 0
            && count($allowed) === count(array_unique($allowed))
            && array_sum(array_column($ready, 'delta')) <= $maxUnits
            && count(array_filter($ready, fn (array $r): bool => ! in_array($r['provider_sku'], $allowed, true))) === 0;
        $checksum = hash('sha256', json_encode([
            'version' => 1,
            'warehouse_id' => $warehouse->id,
            'stock_checksum' => $stock['checksum'],
            'rows' => $rows,
        ], JSON_THROW_ON_ERROR));

        return [
            'warehouse_code' => 'US-LA',
            'provider' => 'ShipOut / MorePlus',
            'source_checksum' => $stock['checksum'],
            'checksum' => $checksum,
            'ready_fifo' => count($ready),
            'ready_units' => array_sum(array_column($ready, 'delta')),
            'blocked' => count($blocked),
            'rows' => $rows,
            'apply_enabled' => app()->environment('production')
                && (bool) config('shipout.production_inventory_apply_enabled', false)
                && (bool) config('shipout.production_fifo_apply_enabled', false)
                && $inApprovedScope,
        ];
    }

    /** @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function inspect(Warehouse $warehouse, array $row): array
    {
        $result = [
            'provider_sku' => $row['provider_sku'],
            'canonical_sku' => $row['canonical_sku'],
            'product_id' => $row['product_id'],
            'qoh' => $row['local_qoh'],
            'physical' => $row['provider_physical'],
            'available' => $row['provider_available'],
            'provider_outbound' => $row['provider_outbound'],
            'reserved' => $row['local_reserved'],
            'inventory_updated_at' => $row['local_updated_at'],
            'delta' => max(0, (int) $row['local_qoh'] - (int) $row['provider_physical']),
            'status' => 'blocked',
            'reason' => $row['reason'],
            'lot_changes' => [],
            'aging_count' => 0,
            'aging_hash' => null,
            'lot_count' => 0,
            'lots_hash' => null,
        ];
        if ($row['reason'] !== 'LOT_OR_AGING_RECONCILIATION_REQUIRED') {
            return $result;
        }
        if (! $row['local_exists'] || ! is_int($row['provider_physical'])
            || (int) $row['local_qoh'] <= $row['provider_physical']) {
            $result['reason'] = 'FIFO_ONLY_PHYSICAL_DECREASE_SUPPORTED';

            return $result;
        }
        if ((int) $row['local_reserved'] !== 0
            || $row['provider_outbound'] !== 0
            || (int) $row['provider_available'] !== (int) $row['provider_physical']) {
            $result['reason'] = 'FIFO_PROVIDER_HOLD_OR_RESERVATION_REQUIRES_REVIEW';

            return $result;
        }
        $productId = (int) $row['product_id'];
        if (WarehouseInventoryLotConsumption::query()
            ->where('warehouse_id', $warehouse->id)->where('product_id', $productId)->exists()) {
            $result['reason'] = 'FIFO_HISTORIC_CONSUMPTION_REQUIRES_REVIEW';

            return $result;
        }
        $lots = WarehouseInventoryLot::query()
            ->where('warehouse_id', $warehouse->id)->where('product_id', $productId)
            ->orderBy('putaway_date')->orderBy('id')->get();
        if ($lots->isEmpty() || $lots->sum('quantity') !== (int) $row['local_qoh']
            || $lots->contains(fn ($lot) => $lot->stock_status !== 'sellable'
                || $lot->putaway_date === null || (int) $lot->quantity < 0)) {
            $result['reason'] = 'FIFO_LOT_SUM_OR_PROVENANCE_INVALID';

            return $result;
        }
        $result['lot_count'] = $lots->count();
        $result['lots_hash'] = $this->snapshotHash($lots->all());
        $aging = WarehouseStorageAgingAccrual::query()
            ->where('warehouse_id', $warehouse->id)->where('product_id', $productId)
            ->orderBy('id')->get();
        $result['aging_count'] = $aging->count();
        $result['aging_hash'] = $this->snapshotHash($aging->all());
        $remaining = $result['delta'];
        foreach ($lots as $lot) {
            $take = min($remaining, (int) $lot->quantity);
            if ($take === 0) {
                continue;
            }
            $result['lot_changes'][] = [
                'id' => (int) $lot->id,
                'date' => $lot->putaway_date->toDateString(),
                'sync_batch_id' => $lot->sync_batch_id,
                'before' => (int) $lot->quantity,
                'after' => (int) $lot->quantity - $take,
                'updated_at' => $lot->updated_at?->toIso8601String(),
            ];
            $remaining -= $take;
            if ($remaining === 0) {
                break;
            }
        }
        if ($remaining !== 0) {
            $result['reason'] = 'FIFO_NOT_ENOUGH_DATED_LOTS';

            return $result;
        }
        $result['status'] = 'ready_fifo';
        $result['reason'] = null;

        return $result;
    }

    /** @param list<object> $records */
    private function snapshotHash(array $records): string
    {
        return hash('sha256', json_encode(
            array_map(fn ($record) => $record->getAttributes(), $records),
            JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Not routable. A separate explicit release flag and checksum are mandatory.
     *
     * @return array<string,mixed>
     */
    public function apply(Warehouse $warehouse, string $expectedChecksum, ?Authenticatable $actor): array
    {
        if (! app()->environment('production')
            || ! config('shipout.production_inventory_apply_enabled', false)
            || ! config('shipout.production_fifo_apply_enabled', false)) {
            throw new RuntimeException('MOREPLUS_FIFO_APPLY_DISABLED');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedChecksum) !== 1) {
            throw new RuntimeException('MOREPLUS_FIFO_CHECKSUM_INVALID');
        }
        $fresh = $this->preview($warehouse);
        if (! hash_equals($expectedChecksum, $fresh['checksum'])) {
            throw new RuntimeException('MOREPLUS_FIFO_SNAPSHOT_CHANGED_PREVIEW_AGAIN');
        }
        $ready = array_values(array_filter(
            $fresh['rows'],
            fn (array $row): bool => $row['status'] === 'ready_fifo',
        ));
        if ($ready === []) {
            throw new RuntimeException('MOREPLUS_FIFO_NO_SAFE_CANDIDATES');
        }
        // Approval is for an exact SKU scope and maximum number of units.
        // Enabling both feature switches never authorizes all future SKUs.
        $approved = (array) config('shipout.production_fifo_approved_skus', []);
        $maximum = (int) config('shipout.production_fifo_max_units', 0);
        if ($approved === [] || $maximum <= 0
            || count($approved) !== count(array_unique($approved))) {
            throw new RuntimeException('MOREPLUS_FIFO_OWNER_SCOPE_NOT_APPROVED');
        }
        foreach ($ready as $candidate) {
            if (! in_array($candidate['provider_sku'], $approved, true)) {
                throw new RuntimeException('MOREPLUS_FIFO_SKU_OUTSIDE_OWNER_SCOPE');
            }
        }
        if (array_sum(array_column($ready, 'delta')) > $maximum) {
            throw new RuntimeException('MOREPLUS_FIFO_UNITS_EXCEED_OWNER_SCOPE');
        }

        return DB::transaction(function () use ($warehouse, $ready, $fresh, $actor): array {
            Warehouse::query()->whereKey($warehouse->id)->lockForUpdate()->firstOrFail();
            $changes = [];
            foreach ($ready as $row) {
                $productId = (int) $row['product_id'];
                $inventory = WarehouseInventory::query()
                    ->where('warehouse_id', $warehouse->id)
                    ->where('product_id', $productId)->lockForUpdate()->firstOrFail();
                if ((int) $inventory->quantity_on_hand !== $row['qoh']
                    || (int) $inventory->quantity_reserved !== 0
                    || $inventory->updated_at?->toIso8601String() !== $row['inventory_updated_at']) {
                    throw new RuntimeException('MOREPLUS_FIFO_ERP_INVENTORY_CHANGED');
                }
                $lots = WarehouseInventoryLot::query()
                    ->where('warehouse_id', $warehouse->id)
                    ->where('product_id', $productId)
                    ->orderBy('putaway_date')->orderBy('id')->lockForUpdate()->get();
                if ($lots->count() !== $row['lot_count']
                    || ! hash_equals($row['lots_hash'], $this->snapshotHash($lots->all()))) {
                    throw new RuntimeException('MOREPLUS_FIFO_LOT_CHANGED');
                }
                if (WarehouseInventoryLotConsumption::query()
                    ->where('warehouse_id', $warehouse->id)
                    ->where('product_id', $productId)->exists()) {
                    throw new RuntimeException('MOREPLUS_FIFO_CONSUMPTION_APPEARED');
                }
                $aging = WarehouseStorageAgingAccrual::query()
                    ->where('warehouse_id', $warehouse->id)
                    ->where('product_id', $productId)
                    ->orderBy('id')->lockForUpdate()->get();
                if ($aging->count() !== $row['aging_count']
                    || ! hash_equals($row['aging_hash'], $this->snapshotHash($aging->all()))) {
                    throw new RuntimeException('MOREPLUS_FIFO_AGING_CHANGED');
                }
                foreach ($row['lot_changes'] as $change) {
                    $lot = $lots->firstWhere('id', (int) $change['id']);
                    if (! $lot || (int) $lot->quantity !== $change['before']
                        || $lot->putaway_date->toDateString() !== $change['date']) {
                        throw new RuntimeException('MOREPLUS_FIFO_LOT_PREIMAGE_INVALID');
                    }
                    $lot->update(['quantity' => $change['after']]);
                }
                $inventory->update(['quantity_on_hand' => $row['physical']]);
                if ((int) WarehouseInventoryLot::query()
                    ->where('warehouse_id', $warehouse->id)
                    ->where('product_id', $productId)->sum('quantity') !== $row['physical']) {
                    throw new RuntimeException('MOREPLUS_FIFO_POST_LOT_SUM_MISMATCH');
                }
                $afterAging = WarehouseStorageAgingAccrual::query()
                    ->where('warehouse_id', $warehouse->id)
                    ->where('product_id', $productId)->orderBy('id')->get();
                if (! hash_equals($row['aging_hash'], $this->snapshotHash($afterAging->all()))) {
                    throw new RuntimeException('MOREPLUS_FIFO_AGING_HISTORY_MUTATED');
                }
                $changes[] = [
                    'provider_sku' => $row['provider_sku'],
                    'canonical_sku' => $row['canonical_sku'],
                    'product_id' => $productId,
                    'old_on_hand' => $row['qoh'],
                    'new_on_hand' => $row['physical'],
                    'units_decreased' => $row['delta'],
                    'lot_changes' => $row['lot_changes'],
                    'aging_rows_preserved' => $row['aging_count'],
                    'aging_sha256' => $row['aging_hash'],
                    'provider_stock_checksum' => $fresh['source_checksum'],
                    'fifo_plan_checksum' => $fresh['checksum'],
                    'owner_maximum_units' => (int) config('shipout.production_fifo_max_units', 0),
                ];
                $this->audit->record(
                    $actor ? AuditActor::user($actor, AuditSource::InternalSystem)
                        : AuditActor::system(AuditSource::InternalSystem),
                    'inventory.moreplus_fifo_reconciled',
                    Warehouse::class,
                    (int) $warehouse->id,
                    before: [
                        'provider_sku' => $row['provider_sku'],
                        'product_id' => $productId,
                        'quantity_on_hand' => $row['qoh'],
                        'aging_sha256' => $row['aging_hash'],
                    ],
                    after: end($changes),
                    reason: 'confirmed_moreplus_provider_fifo_physical_reconciliation',
                );
            }

            return [
                'updated' => count($changes),
                'units_decreased' => array_sum(array_column($changes, 'units_decreased')),
                'blocked' => $fresh['blocked'],
                'checksum' => $fresh['checksum'],
                'changes' => $changes,
            ];
        });
    }
}
