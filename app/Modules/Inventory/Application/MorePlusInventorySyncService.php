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
    ) {}

    /** @return array<string,mixed> */
    public function preview(Warehouse $warehouse): array
    {
        $this->guardWarehouse($warehouse);
        $snapshot = $this->source->snapshot();
        $sourceRows = $snapshot['stock_rows'];
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
            $hasRemovedRow = $product && ! $inventory && WarehouseInventory::withTrashed()
                ->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)
                ->whereNotNull('deleted_at')->exists();
            $physical = $stock['standard_quantity'] ?? null;
            $available = $stock['available_quantity'];
            $reserved = (int) ($inventory?->quantity_reserved ?? 0);
            $qoh = (int) ($inventory?->quantity_on_hand ?? 0);
            $reason = null;
            if ($stock['sku_alias_status'] !== 'verified' || ! $product) {
                $reason = 'SKU_IDENTITY_UNVERIFIED';
            } elseif (($canonicalCounts[$canonicalSku] ?? 0) > 1) {
                $reason = 'SKU_MULTIPLE_PROVIDER_IDENTITIES';
            } elseif ($hasRemovedRow) {
                $reason = 'ERP_REMOVED_INVENTORY_REVIEW_REQUIRED';
            } elseif (! is_int($physical) || $physical < 0 || $physical < $available) {
                $reason = 'PROVIDER_PHYSICAL_QUANTITY_UNVERIFIED';
            } elseif ($reserved > $physical) {
                $reason = 'ERP_RESERVATION_EXCEEDS_PROVIDER_PHYSICAL';
            } elseif ($physical - $reserved > $available) {
                // Provider OMS holds may not exist in ERP reservation ledger.
                // Do not let ERP show more available than the actual provider.
                $reason = 'PROVIDER_AVAILABLE_BELOW_ERP_PROJECTED_AVAILABLE';
            } elseif ($inventory && $qoh !== $physical && $this->hasProtectedLots($warehouse, (int) $product->id)) {
                $reason = 'LOT_OR_AGING_RECONCILIATION_REQUIRED';
            }
            $rows[] = [
                'provider_sku' => $sku,
                'canonical_sku' => $canonicalSku,
                'product_id' => $product ? (int) $product->id : null,
                'provider_physical' => $physical,
                'provider_available' => $available,
                'provider_outbound' => $stock['outbounding_quantity'] ?? null,
                'local_exists' => $inventory !== null,
                'local_qoh' => $qoh,
                'local_reserved' => $reserved,
                'local_updated_at' => $inventory?->updated_at?->toIso8601String(),
                'status' => $reason !== null ? 'blocked' : ($inventory && $qoh === $physical ? 'unchanged' : 'ready'),
                'reason' => $reason,
            ];
        }
        // Stock/list can omit catalog-only SKUs. Missing rows NEVER mean zero.
        $checksum = hash('sha256', json_encode([
            'version' => 1, 'erp_warehouse_id' => (int) $warehouse->id,
            'provider_warehouse_id' => $snapshot['warehouse_id'], 'rows' => $rows,
        ], JSON_THROW_ON_ERROR));

        return [
            'warehouse_code' => 'US-LA', 'provider' => 'ShipOut / MorePlus',
            'source_sku_count' => count($rows),
            'ready' => count(array_filter($rows, fn ($r) => $r['status'] === 'ready')),
            'unchanged' => count(array_filter($rows, fn ($r) => $r['status'] === 'unchanged')),
            'blocked' => count(array_filter($rows, fn ($r) => $r['status'] === 'blocked')),
            'checksum' => $checksum, 'rows' => $rows,
            'apply_enabled' => (bool) config('shipout.production_inventory_apply_enabled', false),
        ];
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
        // Fresh signed provider GET; never apply an old client/session snapshot.
        $fresh = $this->preview($warehouse);
        if (! hash_equals($expectedChecksum, $fresh['checksum'])) {
            throw new RuntimeException('MOREPLUS_INVENTORY_SNAPSHOT_CHANGED_PREVIEW_AGAIN');
        }

        return DB::transaction(function () use ($warehouse, $fresh, $actor): array {
            Warehouse::query()->whereKey($warehouse->id)->lockForUpdate()->firstOrFail();
            $updated = $created = 0;
            $changes = [];
            foreach ($fresh['rows'] as $row) {
                if ($row['status'] === 'blocked') {
                    continue;
                }
                $local = WarehouseInventory::query()->where('warehouse_id', $warehouse->id)
                    ->where('product_id', $row['product_id'])->lockForUpdate()->first();
                if (($local !== null) !== $row['local_exists']
                    || (int) ($local?->quantity_on_hand ?? 0) !== $row['local_qoh']
                    || (int) ($local?->quantity_reserved ?? 0) !== $row['local_reserved']
                    || $local?->updated_at?->toIso8601String() !== $row['local_updated_at']) {
                    throw new RuntimeException('MOREPLUS_INVENTORY_LOCAL_CHANGED_PREVIEW_AGAIN');
                }
                if ($row['status'] === 'unchanged') {
                    continue;
                }
                if ($local && $this->hasProtectedLots($warehouse, (int) $row['product_id'])) {
                    throw new RuntimeException('MOREPLUS_INVENTORY_LOT_CHANGED_PREVIEW_AGAIN');
                }
                $changes[] = [
                    'sku' => $row['canonical_sku'], 'provider_sku' => $row['provider_sku'],
                    'product_id' => $row['product_id'],
                    'previous_on_hand' => $row['local_exists'] ? $row['local_qoh'] : null,
                    'new_on_hand' => $row['provider_physical'],
                    'preserved_reserved' => $row['local_reserved'],
                ];
                if ($local) {
                    $local->update(['quantity_on_hand' => $row['provider_physical']]);
                    $updated++;
                } else {
                    WarehouseInventory::query()->create([
                        'warehouse_id' => $warehouse->id,
                        'product_id' => $row['product_id'],
                        'quantity_on_hand' => $row['provider_physical'],
                        'quantity_reserved' => 0,
                    ]);
                    $created++;
                }
            }
            $result = [
                'updated' => $updated, 'created' => $created,
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
