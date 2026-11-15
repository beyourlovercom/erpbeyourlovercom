<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventoryLot;
use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutProductionInventoryAudit;
use App\Modules\Products\Infrastructure\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * One-way MorePlus -> ERP stock import. Never submits an OMS order or edits
 * provider stock. Physical standardQuantity is NOT availableQuantity.
 */
final class MorePlusInventorySyncService
{
    public function __construct(
        private readonly ShipOutProductionInventoryAudit $source,
        private readonly AuditRecorder $audit,
        private readonly WarehouseLastReadStockService $lastRead,
    ) {}

    /** @return array<string,mixed> */
    public function preview(Warehouse $warehouse, ?array $sourceSnapshot = null): array
    {
        $this->guardWarehouse($warehouse);
        $snapshot = $sourceSnapshot ?? $this->source->snapshot();
        $sourceRows = $snapshot['stock_rows'];
        if ($sourceRows === []) {
            throw new RuntimeException('MOREPLUS_PROVIDER_STOCK_EMPTY_REVIEW_REQUIRED');
        }
        // Two provider SKUs may never silently target the same ERP inventory row.
        $canonicalCounts = array_count_values(array_filter(array_column($sourceRows, 'canonical_sku')));
        $canonical = array_values(array_unique(array_filter(array_column($sourceRows, 'canonical_sku'))));
        $products = Product::query()->whereIn('sku', $canonical)->get()->keyBy('sku');
        $inventories = WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->get()->keyBy('product_id');
        $rows = [];
        foreach ($sourceRows as $stock) {
            $sku = (string) $stock['provider_sku'];
            $canonicalSku = $stock['canonical_sku'];
            $product = $canonicalSku ? $products->get($canonicalSku) : null;
            $inventory = $product ? $inventories->get($product->id) : null;
            // A verified physical SKU may resurrect its own previously archived
            // ERP mirror row: do not duplicate the product or invent stock.
            if ($product && ! $inventory) {
                $inventory = WarehouseInventory::withTrashed()
                    ->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)
                    ->first();
            }
            $needsRestore = (bool) ($inventory?->trashed() ?? false);
            $physical = $stock['standard_quantity'] ?? null;
            $available = $stock['available_quantity'];
            $reserved = (int) ($inventory?->quantity_reserved ?? 0);
            $qoh = (int) ($inventory?->quantity_on_hand ?? 0);
            $reason = null;
            if ($stock['sku_alias_status'] !== 'verified' || ! $product) {
                $reason = 'SKU_IDENTITY_UNVERIFIED';
            } elseif (($canonicalCounts[$canonicalSku] ?? 0) > 1) {
                $reason = 'SKU_MULTIPLE_PROVIDER_IDENTITIES';
            } elseif (! is_int($physical) || $physical < 0 || $physical < $available) {
                $reason = 'PROVIDER_PHYSICAL_QUANTITY_UNVERIFIED';
            }
            // Source stock is authoritative. ERP reservations, provider holds,
            // dated lots and aging accruals are accounting warnings, NOT sync gates.
            $accountingWarning = null;
            if ($reason === null && $reserved > $physical) {
                $accountingWarning = 'ERP_RESERVATION_EXCEEDS_PROVIDER_PHYSICAL';
            } elseif ($reason === null && $physical - $reserved > $available) {
                $accountingWarning = 'PROVIDER_AVAILABLE_BELOW_ERP_PROJECTED_AVAILABLE';
            } elseif ($reason === null && $inventory && $qoh !== $physical
                && $this->hasProtectedLots($warehouse, (int) $product->id)) {
                $accountingWarning = 'LOT_OR_AGING_RECONCILIATION_REQUIRED';
            }
            $rows[] = [
                'provider_sku' => $sku,
                'canonical_sku' => $canonicalSku,
                'product_id' => $product ? (int) $product->id : null,
                'provider_physical' => $physical,
                'provider_available' => $available,
                'provider_outbound' => $stock['outbounding_quantity'] ?? null,
                'local_exists' => $inventory !== null,
                'local_deleted' => $needsRestore,
                'local_qoh' => $qoh,
                'local_reserved' => $reserved,
                'local_provider_available' => $inventory?->provider_available_quantity,
                'local_updated_at' => $inventory?->updated_at?->toIso8601String(),
                'status' => $reason !== null ? 'blocked' : ($inventory && ! $needsRestore
                    && $qoh === $physical && $inventory->provider_available_quantity === $available
                    ? 'unchanged' : 'ready'),
                'reason' => $reason,
                'accounting_warning' => $accountingWarning,
            ];
        }
        // A complete, signed warehouse stock/list is authoritative: once all
        // returned provider SKUs are verified, ERP-only rows absent from the
        // full stock result are no longer physically stocked at MorePlus.
        // Preserve their historical lot and accounting ledgers separately.
        $returnedProductIds = array_values(array_unique(array_filter(array_column($rows, 'product_id'))));
        $absent = $inventories->filter(fn (WarehouseInventory $i): bool =>
            ! in_array((int) $i->product_id, $returnedProductIds, true))
            ->sortBy('product_id')->map(fn (WarehouseInventory $i): array => [
                'id' => (int) $i->id,
                'product_id' => (int) $i->product_id,
                'sku' => (string) $i->product?->sku,
                'local_qoh' => (int) $i->quantity_on_hand,
                'local_reserved' => (int) $i->quantity_reserved,
                'local_provider_available' => $i->provider_available_quantity,
                'local_updated_at' => $i->updated_at?->toIso8601String(),
            ])->values()->all();
        $checksum = hash('sha256', json_encode([
            'version' => 2, 'erp_warehouse_id' => (int) $warehouse->id,
            'provider_warehouse_id' => $snapshot['warehouse_id'], 'rows' => $rows,
            'absent_rows' => $absent,
        ], JSON_THROW_ON_ERROR));

        return [
            'warehouse_code' => 'US-LA', 'provider' => 'ShipOut / MorePlus',
            'source_sku_count' => count($rows),
            'ready' => count(array_filter($rows, fn ($r) => $r['status'] === 'ready')),
            'unchanged' => count(array_filter($rows, fn ($r) => $r['status'] === 'unchanged')),
            'blocked' => count(array_filter($rows, fn ($r) => $r['status'] === 'blocked')),
            'checksum' => $checksum, 'rows' => $rows,
            'absent_rows' => $absent, 'absent_count' => count($absent),
            'absent_qoh' => array_sum(array_column($absent, 'local_qoh')),
            // An unresolved provider identity prevents zeros of locally-absent rows.
            'absent_zero_eligible' => count(array_filter($rows, fn ($r) => $r['status'] === 'blocked')) === 0,
            'apply_enabled' => (bool) config('shipout.production_inventory_apply_enabled', false),
        ];
    }

    /**
     * An explicit API read independently persists last-known stock, even if
     * constructing or applying the ERP main-stock transaction later fails.
     * Existing preview() stays pure for prior read-only callers and tests.
     *
     * @return array<string,mixed>
     */
    public function previewWithLastRead(Warehouse $warehouse, string $readSource = 'api_read'): array
    {
        $this->guardWarehouse($warehouse);
        try {
            $snapshot = $this->source->snapshot();
            $readAt = CarbonImmutable::now('UTC');
            $this->lastRead->captureMorePlus($warehouse, $snapshot, $readAt, $readSource);
        } catch (\Throwable $error) {
            $this->lastRead->markReadFailure($warehouse, 'MOREPLUS_API_READ_FAILED');
            throw $error;
        }
        try {
            return $this->preview($warehouse, $snapshot) + [
                'last_read_captured_at' => $readAt->toIso8601String(),
            ];
        } catch (\Throwable $error) {
            $this->lastRead->markApplyResult($warehouse, $readAt, false);
            throw $error;
        }
    }

    /**
     * One-click, GET-only provider-to-ERP mirror. No provider POST and no FIFO/P9
     * adjustment: those historical ledgers remain independent accounting facts.
     * The calling route is permission-checked and US-LA scoped.
     *
     * @return array<string,mixed>
     */
    public function syncProvider(Warehouse $warehouse, ?Authenticatable $actor): array
    {
        $this->guardWarehouse($warehouse);
        if (! app()->environment('production')) {
            throw new RuntimeException('MOREPLUS_PROVIDER_SYNC_PRODUCTION_ONLY');
        }

        $fresh = $this->previewWithLastRead($warehouse, 'sync');
        $readAt = CarbonImmutable::parse($fresh['last_read_captured_at']);
        try {
            $result = $this->applySnapshot($warehouse, $fresh, $actor);
        } catch (\Throwable $error) {
            $this->lastRead->markApplyResult($warehouse, $readAt, false);
            throw $error;
        }
        $this->lastRead->markApplyResult($warehouse, $readAt, true);

        return $result;
    }

    /** @return array{updated:int,created:int,unchanged:int,blocked:int,checksum:string} */
    public function apply(Warehouse $warehouse, string $expectedChecksum, ?Authenticatable $actor): array
    {
        $this->guardWarehouse($warehouse);
        if (! app()->environment('production') || ! (bool) config('shipout.production_inventory_apply_enabled', false)) {
            throw new RuntimeException('MOREPLUS_INVENTORY_APPLY_DISABLED');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedChecksum) !== 1) {
            throw new RuntimeException('MOREPLUS_INVENTORY_CHECKSUM_INVALID');
        }
        // The legacy confirmation path is also an explicit signed stock read.
        // Save its independent reference before attempting ERP main-stock write.
        $fresh = $this->previewWithLastRead($warehouse, 'sync');
        $readAt = CarbonImmutable::parse($fresh['last_read_captured_at']);
        if (! hash_equals($expectedChecksum, $fresh['checksum'])) {
            $this->lastRead->markApplyResult($warehouse, $readAt, false);
            throw new RuntimeException('MOREPLUS_INVENTORY_SNAPSHOT_CHANGED_PREVIEW_AGAIN');
        }
        try {
            $result = $this->applySnapshot($warehouse, $fresh, $actor);
        } catch (\Throwable $error) {
            $this->lastRead->markApplyResult($warehouse, $readAt, false);
            throw $error;
        }
        $this->lastRead->markApplyResult($warehouse, $readAt, true);

        return $result;
    }

    /** @param array<string,mixed> $fresh
     * @return array<string,mixed>
     */
    private function applySnapshot(Warehouse $warehouse, array $fresh, ?Authenticatable $actor): array
    {
        return DB::transaction(function () use ($warehouse, $fresh, $actor): array {
            Warehouse::query()->whereKey($warehouse->id)->lockForUpdate()->firstOrFail();
            $updated = $created = 0;
            $changes = [];
            foreach ($fresh['rows'] as $row) {
                if ($row['status'] === 'blocked') {
                    continue;
                }
                $local = WarehouseInventory::withTrashed()->where('warehouse_id', $warehouse->id)
                    ->where('product_id', $row['product_id'])->lockForUpdate()->first();
                if (($local !== null) !== $row['local_exists']
                    || (bool) ($local?->trashed() ?? false) !== ($row['local_deleted'] ?? false)
                    || (int) ($local?->quantity_on_hand ?? 0) !== $row['local_qoh']
                    || (int) ($local?->quantity_reserved ?? 0) !== $row['local_reserved']
                    || $local?->updated_at?->toIso8601String() !== $row['local_updated_at']) {
                    throw new RuntimeException('MOREPLUS_INVENTORY_LOCAL_CHANGED_PREVIEW_AGAIN');
                }
                if ($row['status'] === 'unchanged') {
                    continue;
                }
                // Keep lot, FIFO consumption and P9 historical records unchanged.
                // Any ledger-to-physical mismatch remains an accounting exception.
                $changes[] = [
                    'sku' => $row['canonical_sku'], 'provider_sku' => $row['provider_sku'],
                    'product_id' => $row['product_id'],
                    'previous_on_hand' => $row['local_exists'] ? $row['local_qoh'] : null,
                    'new_on_hand' => $row['provider_physical'],
                    'preserved_reserved' => $row['local_reserved'],
                    'provider_available' => $row['provider_available'],
                    'accounting_warning' => $row['accounting_warning'],
                ];
                if ($local) {
                    if ($local->trashed()) {
                        $local->restore();
                    }
                    $local->update([
                        'quantity_on_hand' => $row['provider_physical'],
                        'provider_available_quantity' => $row['provider_available'],
                        'provider_stock_synced_at' => now(),
                    ]);
                    $updated++;
                } else {
                    WarehouseInventory::query()->create([
                        'warehouse_id' => $warehouse->id,
                        'product_id' => $row['product_id'],
                        'quantity_on_hand' => $row['provider_physical'],
                        'quantity_reserved' => 0,
                        'provider_available_quantity' => $row['provider_available'],
                        'provider_stock_synced_at' => now(),
                    ]);
                    $created++;
                }
            }
            $absentZeroed = 0;
            if ($fresh['absent_zero_eligible']) {
                foreach ($fresh['absent_rows'] as $stale) {
                    $local = WarehouseInventory::query()->where('warehouse_id', $warehouse->id)
                        ->whereKey($stale['id'])->lockForUpdate()->firstOrFail();
                    if ((int) $local->product_id !== $stale['product_id']
                        || (int) $local->quantity_on_hand !== $stale['local_qoh']
                        || (int) $local->quantity_reserved !== $stale['local_reserved']
                        || $local->provider_available_quantity !== $stale['local_provider_available']
                        || $local->updated_at?->toIso8601String() !== $stale['local_updated_at']) {
                        throw new RuntimeException('MOREPLUS_INVENTORY_LOCAL_CHANGED_PREVIEW_AGAIN');
                    }
                    $changes[] = [
                        'sku' => $stale['sku'], 'product_id' => $stale['product_id'],
                        'previous_on_hand' => $stale['local_qoh'], 'new_on_hand' => 0,
                        'provider_available' => 0, 'preserved_reserved' => $stale['local_reserved'],
                        'reason' => 'NOT_RETURNED_IN_COMPLETE_PROVIDER_STOCK_LIST',
                    ];
                    $local->update([
                        'quantity_on_hand' => 0,
                        'provider_available_quantity' => 0,
                        'provider_stock_synced_at' => now(),
                    ]);
                    $updated++;
                    $absentZeroed++;
                }
            }
            $result = [
                'updated' => $updated, 'created' => $created,
                'absent_zeroed' => $absentZeroed,
                'absent_waiting_identity' => $fresh['absent_zero_eligible'] ? 0 : count($fresh['absent_rows']),
                'unchanged' => $fresh['unchanged'], 'blocked' => $fresh['blocked'],
                'checksum' => $fresh['checksum'],
                'source_sku_count' => $fresh['source_sku_count'],
                'changes' => $changes,
                'blocked_details' => array_values(array_map(
                    fn (array $row): array => [
                        'provider_sku' => $row['provider_sku'],
                        'reason' => $row['reason'],
                    ],
                    array_filter($fresh['rows'], fn (array $row): bool => $row['status'] === 'blocked')
                )),
            ];
            $this->audit->record(
                $actor ? AuditActor::user($actor, AuditSource::InternalSystem) : AuditActor::system(AuditSource::InternalSystem),
                'inventory.moreplus_provider_stock_applied', Warehouse::class, (int) $warehouse->id,
                after: $result, reason: 'confirmed_moreplus_provider_inventory_sync',
            );

            return $result;
        });
    }

    private function guardWarehouse(Warehouse $warehouse): void
    {
        if (strtoupper(trim((string) $warehouse->code)) !== 'US-LA') {
            throw new RuntimeException('MOREPLUS_WAREHOUSE_ONLY');
        }
    }

    private function hasProtectedLots(Warehouse $warehouse, int $productId): bool
    {
        if (WarehouseInventoryLot::query()->where('warehouse_id', $warehouse->id)->where('product_id', $productId)->exists()) {
            return true;
        }
        foreach (['warehouse_inventory_lot_consumptions', 'warehouse_storage_aging_accruals'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->where('warehouse_id', $warehouse->id)->where('product_id', $productId)->exists()) {
                return true;
            }
        }

        return false;
    }
}
