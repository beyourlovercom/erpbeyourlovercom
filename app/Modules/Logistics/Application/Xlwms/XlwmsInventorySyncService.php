<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventoryLot;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventoryLotConsumption;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsInventorySnapshot;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsInventorySnapshotRow;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Products\Application\SkuIdentityResolver;
use App\Modules\Products\Application\SkuResolveResult;
use App\Modules\Products\Application\SkuMatchKey;
use App\Modules\Products\Infrastructure\Models\ProductSkuAlias;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class XlwmsInventorySyncService
{
    private readonly XlwmsLotReconciliationService $lotReconciliation;

    public function __construct(private readonly XlwmsInventoryGateway $gateway, private readonly SkuIdentityResolver $resolver, private readonly XlwmsInventoryPageParser $parser, private readonly AuditRecorder $audit, ?XlwmsLotReconciliationService $lotReconciliation = null)
    {
        $this->lotReconciliation = $lotReconciliation ?? new XlwmsLotReconciliationService();
    }

    /** @return array<string,mixed> */
    public function sync(bool $apply = false): array
    {
        if ($apply && ! (bool) config('xlwms.inventory_apply_enabled', false)) throw new RuntimeException('XLWMS_INVENTORY_APPLY_DISABLED');
        $warehouse = Warehouse::query()->where('code', 'US-SM')->firstOrFail();
        $connection = WarehouseOpenApiConnection::query()->where('warehouse_id', $warehouse->id)->where('provider', 'xlwms')->firstOrFail();
        $whCode = trim((string) $connection->oms_warehouse_code);
        if ($whCode === '') throw new RuntimeException('OMS_WAREHOUSE_CODE_NOT_CONFIGURED');
        $pageSize = 10;

        // Product pages are account-level; a product row is not required to carry whCode.
        $products = $this->allPages(fn (int $page) => $this->gateway->productPage($connection, ['page' => $page, 'pageSize' => $pageSize]), fn (array $response, int $page) => $this->parser->productPage($response, $page, $pageSize), false);
        $providerSkus = [];
        foreach ($products as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '') throw new RuntimeException('OMS_PRODUCT_SKU_INVALID');
            if (isset($providerSkus[$sku])) throw new RuntimeException('OMS_DUPLICATE_SKU');
            $providerSkus[$sku] = true;
        }

        $inventoryRows = $this->allPages(fn (int $page) => $this->gateway->integratedInventoryPage($connection, ['page' => $page, 'pageSize' => $pageSize, 'whCode' => $whCode, 'stockType' => 0]), fn (array $response, int $page) => $this->parser->inventoryPage($response, $whCode, $page, $pageSize), true);
        if ($inventoryRows === []) {
            $checksum = hash('sha256', json_encode(['snapshot_schema' => 2, 'wh_code' => $whCode, 'products' => array_keys($providerSkus), 'inventory' => []], JSON_THROW_ON_ERROR));
            return $this->result($this->persistAndMaybeApply($warehouse, $whCode, $checksum, $providerSkus, [], [], [], [], 0, 0, 0, 'not_ready', false, []));
        }

        $normalized = [];
        foreach ($inventoryRows as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '' || isset($normalized[$sku])) throw new RuntimeException('OMS_DUPLICATE_OR_MISSING_INVENTORY_SKU');
            if (! isset($providerSkus[$sku])) throw new RuntimeException('OMS_INVENTORY_SKU_OUTSIDE_PRODUCT_SCOPE');
            if ((int) ($row['stockType'] ?? -1) !== 0) throw new RuntimeException('OMS_UNKNOWN_QUALITY');
            $detail = $row['productStockDtl'] ?? null;
            if (! is_array($detail) || array_keys($detail) !== ['availableAmount', 'lockAmount', 'transportAmount']) throw new RuntimeException('OMS_PRODUCT_STOCK_DETAIL_INVALID');
            $qoh = $this->parser->nonNegativeInt($row['productTotalAmount'] ?? null, 'PRODUCT_TOTAL');
            $available = $this->parser->nonNegativeInt($detail['availableAmount'] ?? null, 'AVAILABLE');
            $locked = $this->parser->nonNegativeInt($detail['lockAmount'] ?? null, 'LOCKED');
            $inTransit = $this->parser->nonNegativeInt($detail['transportAmount'] ?? null, 'IN_TRANSIT');
            if ($qoh !== $available + $locked) throw new RuntimeException('OMS_STOCK_TOTAL_INCONSISTENT');
            $normalized[$sku] = ['sku' => $sku, 'total' => $qoh, 'available' => $available, 'locked' => $locked, 'in_transit' => $inTransit];
        }

        $rows = []; $mismatches = []; $mapped = $unmatched = $blocked = 0;
        // A catalog SKU without a stock-ledger row is UNKNOWN, never implicit zero.
        // Only current, warehouse-scoped stock rows may drive inventory updates.
        foreach (array_keys($normalized) as $sku) {
            $resolution = $this->resolver->resolve($sku);
            $resolved = ($resolution->type === SkuResolveResult::CANONICAL || ($resolution->type === SkuResolveResult::ALIAS && $this->hasShimaiAliasEvidence($sku))) ? $resolution->product?->getKey() : null;
            if ($resolved === null) {
                $rows[] = ['sku' => $sku, 'status' => 'unmatched', 'stock' => $normalized[$sku] ?? null,
                    'reason' => 'OMS_SKU_NOT_VERIFIED_ERP_IDENTITY'];
                $unmatched++; continue;
            }
            $mapped++;
            $stock = $normalized[$sku] ?? null;
            $reason = $stock === null ? 'ABSENT_LEDGER_NOT_READY' : null;
            $existingInventory = $stock === null ? null : WarehouseInventory::query()
                ->where('warehouse_id', $warehouse->id)->where('product_id', (int) $resolved)->first();
            if ($stock !== null && $stock['locked'] > (int) ($existingInventory?->quantity_reserved ?? 0)) {
                $reason = 'OMS_LOCKED_STOCK_EXCEEDS_LOCAL_RESERVATIONS';
            }
            if ($stock !== null && ! $this->v2Enabled() && $this->hasProtectedState($warehouse->id, (int) $resolved)) $reason = 'LOT_RECONCILIATION_REQUIRED';
            if ($reason !== null) { $blocked++; $mismatches[] = ['sku' => $sku, 'reason' => $reason]; }
            $rows[] = ['sku' => $sku, 'status' => $reason ? 'blocked' : 'matched', 'product_id' => (int) $resolved, 'stock' => $stock, 'reason' => $reason];
        }
        // Persist all OMS catalog SKUs, including SKUs with no ledger in US-SM.
        // Catalog-only is deliberately not "unmatched" and never means stock zero.
        foreach (array_keys($providerSkus) as $sku) {
            if (isset($normalized[$sku])) continue;
            $resolution = $this->resolver->resolve($sku);
            $catalogProductId = ($resolution->type === SkuResolveResult::CANONICAL
                || ($resolution->type === SkuResolveResult::ALIAS && $this->hasShimaiAliasEvidence($sku)))
                ? $resolution->product?->getKey() : null;
            $rows[] = ['sku' => $sku, 'status' => 'catalog_only',
                'product_id' => $catalogProductId === null ? null : (int) $catalogProductId,
                'reason' => 'ABSENT_WAREHOUSE_STOCK_LEDGER'];
        }
        // Include local revision in identity so unchanged OMS data can repair a later ERP drift.
        $localBaseline = [];
        foreach ($rows as $row) {
            if (! isset($row['product_id'])) continue;
            $entry = WarehouseInventory::query()->where('warehouse_id', $warehouse->id)
                ->where('product_id', (int) $row['product_id'])->first();
            $localBaseline[$row['sku']] = [
                'product_id' => $row['product_id'],
                'qoh' => $entry?->quantity_on_hand,
                'reserved' => $entry?->quantity_reserved,
                'reason' => $row['reason'] ?? null,
            ];
        }
        $checksum = hash('sha256', json_encode(['snapshot_schema' => $this->v2Enabled() ? 3 : 2, 'reconciliation_mode' => $this->v2Enabled() ? 'lot_v2' : 'quantity_v1', 'wh_code' => $whCode, 'catalog_skus' => array_keys($providerSkus), 'inventory' => $normalized, 'local_baseline' => $localBaseline, 'lot_baseline' => $this->v2LotBaseline($warehouse, $rows), 'unknown_age_baseline' => $this->v2UnknownAgeBaseline($warehouse, $rows)], JSON_THROW_ON_ERROR));
        $status = ($unmatched > 0 || $blocked > 0) ? 'blocked' : 'read_only';
        $snapshot = $this->persistAndMaybeApply($warehouse, $whCode, $checksum, $providerSkus, $normalized, $rows, $mismatches, $mapped, $unmatched, $blocked, $status, $apply, $localBaseline);
        $this->audit->record(AuditActor::system(AuditSource::InternalSystem), 'inventory.xlwms_snapshot_'.$snapshot->status, $snapshot::class, $snapshot->id, after: $this->result($snapshot), reason: 'xlwms_inventory_sync');
        return $this->result($snapshot);
    }

    /** @return list<array<string,mixed>> */
    private function allPages(callable $fetch, callable $parse, bool $allowEmpty): array
    {
        $all = []; $total = null; $pages = null;
        for ($page = 1; $page <= 1000; $page++) {
            $parsed = $parse($fetch($page), $page);
            if ($total === null) { $total = $parsed['total']; $pages = $parsed['pages']; }
            if ($parsed['total'] !== $total || $parsed['pages'] !== $pages) throw new RuntimeException('OMS_PAGINATION_TOTAL_CHANGED');
            $all = [...$all, ...$parsed['rows']];
            if ($total === 0) {
                if (! $allowEmpty || $parsed['rows'] !== []) throw new RuntimeException('OMS_PAGINATION_TRUNCATED');
                return [];
            }
            if ($page === $pages) {
                if (count($all) !== $total) throw new RuntimeException('OMS_PAGINATION_TRUNCATED');
                if ($total === 0 && ! $allowEmpty) throw new RuntimeException('OMS_EMPTY_PRODUCT_CATALOG');
                return $all;
            }
            if ($page >= $pages || $parsed['rows'] === []) throw new RuntimeException('OMS_PAGINATION_INCOMPLETE');
        }
        throw new RuntimeException('OMS_PAGINATION_INCOMPLETE');
    }

    private function persistAndMaybeApply(Warehouse $warehouse, string $whCode, string $checksum, array $providerSkus, array $normalized, array $rows, array $mismatches, int $mapped, int $unmatched, int $blocked, string $status, bool $apply, array $localBaseline): XlwmsInventorySnapshot
    {
        return DB::transaction(function () use ($warehouse, $whCode, $checksum, $providerSkus, $normalized, $rows, $mismatches, $mapped, $unmatched, $blocked, $status, $apply, $localBaseline): XlwmsInventorySnapshot {
            if ($this->v2Enabled()) {
                $plan = $this->lotReconciliation->plan($warehouse, $rows, $localBaseline);
                $allMismatches = [...$mismatches, ...$plan['blocked']];
                if ($unmatched > 0) {
                    $allMismatches[] = ['reason' => 'OMS_UNMATCHED_STOCK_SKUS', 'count' => $unmatched];
                }
                $byProduct = collect($rows)->filter(fn (array $r): bool => isset($r['product_id']) && $r['status'] === 'matched')
                    ->groupBy('product_id')->filter(fn ($mapped): bool => $mapped->count() > 1);
                foreach ($byProduct as $productId => $duplicates) {
                    $allMismatches[] = ['reason' => 'OMS_MULTIPLE_SKUS_ONE_ERP_PRODUCT', 'product_id' => (int) $productId,
                        'skus' => $duplicates->pluck('sku')->values()->all()];
                }
                if ($apply && $allMismatches === []) {
                    $allMismatches = [...$allMismatches,
                        ...app(XlwmsInventoryApplyGuard::class)->blockers($warehouse, $plan, $rows, $checksum)];
                }
                $v2Status = $allMismatches === [] ? 'read_only' : 'blocked';
                $snapshot = XlwmsInventorySnapshot::query()->where('warehouse_id', $warehouse->id)->where('checksum', $checksum)->lockForUpdate()->first();
                if ($snapshot?->status === 'applied' || ($snapshot !== null && ! $apply)) {
                    $snapshot->touch();
                    return $snapshot->fresh();
                }
                if ($snapshot === null) $snapshot = $this->storeSnapshot($warehouse, $whCode, $checksum, $providerSkus, $normalized, $rows, $allMismatches, $mapped, $unmatched, count($allMismatches), $v2Status, false, [], $plan['summary']);
                if ($allMismatches !== [] && $apply) {
                    $snapshot->update(['status' => 'blocked', 'blocked_sku_count' => count($allMismatches),
                        'mismatches' => $allMismatches]);
                }
                if ($allMismatches !== [] || ! $apply) return $snapshot->fresh();
                $this->lotReconciliation->apply($warehouse, $plan, (int) $snapshot->id, $checksum);
                $snapshot->update(['status' => 'applied', 'applied_at' => now(), 'summary' => [...($snapshot->summary ?? []), 'v2_applied' => true]]);
                return $snapshot->fresh();
            }
            $snapshot = XlwmsInventorySnapshot::query()->where('warehouse_id', $warehouse->id)->where('checksum', $checksum)->lockForUpdate()->first();
            if ($snapshot?->status === 'applied' || ($snapshot !== null && ! $apply)) {
                $snapshot->touch(); // updated_at is last successful read of this unchanged source state
                return $snapshot->fresh();
            }
            if ($snapshot === null) $snapshot = $this->storeSnapshot($warehouse, $whCode, $checksum, $providerSkus, $normalized, $rows, $mismatches, $mapped, $unmatched, $blocked, $status, false, []);
            if (! $apply || $snapshot->status === 'blocked' || $snapshot->status === 'not_ready') return $snapshot->fresh();

            // Every current blocker is checked under lock before the first quantity write.
            $productIds = collect($rows)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->values();
            $current = WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->whereIn('product_id', $productIds)->lockForUpdate()->get()->keyBy('product_id');
            $currentMismatches = [];
            foreach ($rows as $row) {
                if ($row['status'] !== 'matched' || ! isset($row['stock'])) continue;
                $inventory = $current->get($row['product_id']);
                if (($inventory && (int) $inventory->quantity_reserved > (int) $row['stock']['total'])
                    || (int) ($inventory?->quantity_reserved ?? 0) < (int) $row['stock']['locked']
                    || $this->hasProtectedState($warehouse->id, (int) $row['product_id'])) {
                    $currentMismatches[] = ['sku' => $row['sku'], 'reason' => 'LOT_OR_RESERVATION_OR_PROVIDER_LOCK_CONFLICT'];
                }
            }
            if ($currentMismatches !== []) {
                $snapshot->update(['status' => 'blocked', 'blocked_sku_count' => count($currentMismatches), 'mismatches' => $currentMismatches]);
                return $snapshot->fresh();
            }
            foreach ($rows as $row) if ($row['status'] === 'matched' && isset($row['stock'])) WarehouseInventory::query()->updateOrCreate(['warehouse_id' => $warehouse->id, 'product_id' => $row['product_id']], ['quantity_on_hand' => $row['stock']['total']]);
            $snapshot->update(['status' => 'applied', 'applied_at' => now()]);
            return $snapshot->fresh();
        });
    }

    private function storeSnapshot(Warehouse $warehouse, string $whCode, string $checksum, array $providerSkus, array $normalized, array $rows, array $mismatches, int $mapped, int $unmatched, int $blocked, string $status, bool $applied, array $errors, array $extraSummary = []): XlwmsInventorySnapshot
    {
        $changes = [];
        foreach ($rows as $row) {
            if (! isset($row['product_id'], $row['stock']['total'])) continue;
            $existing = WarehouseInventory::query()->where('warehouse_id', $warehouse->id)
                ->where('product_id', (int) $row['product_id'])->first();
            $before = $existing?->quantity_on_hand;
            $after = (int) $row['stock']['total'];
            if ($before === null || (int) $before !== $after) {
                $changes[] = ['sku' => $row['sku'], 'erp' => $before === null ? null : (int) $before,
                    'oms' => $after, 'delta' => $before === null ? null : $after - (int) $before,
                    'ready' => $row['status'] === 'matched'];
            }
        }
        $snapshot = XlwmsInventorySnapshot::query()->create(['warehouse_id' => $warehouse->id, 'wh_code' => $whCode, 'checksum' => $checksum, 'status' => $status, 'fetched_at' => now(), 'applied_at' => $applied ? now() : null, 'provider_sku_count' => count($providerSkus), 'mapped_sku_count' => $mapped, 'unmatched_sku_count' => $unmatched, 'blocked_sku_count' => $blocked, 'summary' => ['oms_rows' => count($normalized), 'oms_good_qty' => array_sum(array_column($normalized, 'total')),
                    'oms_available_qty' => array_sum(array_column($normalized, 'available')),
                    'oms_locked_qty' => array_sum(array_column($normalized, 'locked')),
                    'in_transit' => array_sum(array_column($normalized, 'in_transit')), 'changes' => $changes, ...$extraSummary], 'mismatches' => $mismatches, 'errors' => $errors]);
        foreach ($rows as $row) XlwmsInventorySnapshotRow::query()->create(['snapshot_id' => $snapshot->id, 'provider_sku' => $row['sku'], 'product_id' => $row['product_id'] ?? null, 'resolution_status' => $row['status'], 'oms_total' => $row['stock']['total'] ?? null, 'oms_available' => $row['stock']['available'] ?? null, 'oms_locked' => $row['stock']['locked'] ?? null, 'oms_in_transit' => $row['stock']['in_transit'] ?? null, 'mismatch_reason' => $row['reason'] ?? null]);
        return $snapshot;
    }

    /** Only reuse existing, source-verified Shimai PI aliases; never fuzzy-match model suffixes. */
    private function hasShimaiAliasEvidence(string $providerSku): bool
    {
        $key = SkuMatchKey::compute($providerSku);
        return $key !== null && ProductSkuAlias::query()
            ->where('match_key', $key)
            ->where('source_type', 'smgj_pi')
            ->where('source_reference', 'like', '%世迈仓%')
            ->exists();
    }

    private function hasProtectedState(int $warehouseId, int $productId): bool
    {
        return WarehouseInventory::query()->where('warehouse_id', $warehouseId)->where('product_id', $productId)->whereNotNull('inbound_at')->exists() || WarehouseInventoryLot::query()->where('warehouse_id', $warehouseId)->where('product_id', $productId)->exists() || WarehouseInventoryLotConsumption::query()->where('warehouse_id', $warehouseId)->where('product_id', $productId)->exists();
    }

    private function v2Enabled(): bool
    {
        return (bool) config('xlwms.lot_reconciliation_v2_enabled', false);
    }

    /** @return array<int,array<string,mixed>> */
    private function v2LotBaseline(Warehouse $warehouse, array $rows): array
    {
        if (! $this->v2Enabled() || ! \Illuminate\Support\Facades\Schema::hasTable('warehouse_inventory_lots')) return [];
        $ids = collect($rows)->pluck('product_id')->filter()->map(fn ($id): int => (int) $id)->unique();
        return WarehouseInventoryLot::query()->where('warehouse_id', $warehouse->id)->whereIn('product_id', $ids)->orderBy('product_id')->orderBy('putaway_date')->orderBy('id')->get()->map(fn (WarehouseInventoryLot $lot): array => ['id' => (int) $lot->id, 'product_id' => (int) $lot->product_id, 'status' => $lot->stock_status, 'putaway_date' => $lot->putaway_date?->toDateString(), 'quantity' => (int) $lot->quantity, 'sync_batch_id' => $lot->sync_batch_id])->all();
    }

    /** @return array<int,int> */
    private function v2UnknownAgeBaseline(Warehouse $warehouse, array $rows): array
    {
        if (! $this->v2Enabled() || ! \Illuminate\Support\Facades\Schema::hasTable('xlwms_inventory_unverified_ages')) return [];
        $ids = collect($rows)->pluck('product_id')->filter()->map(fn ($id): int => (int) $id)->unique();
        return \Illuminate\Support\Facades\DB::table('xlwms_inventory_unverified_ages')->where('warehouse_id', $warehouse->id)->whereIn('product_id', $ids)->orderBy('product_id')->pluck('unknown_age_quantity', 'product_id')->map(fn ($quantity): int => (int) $quantity)->all();
    }

    /** @return array<string,mixed> */
    private function result(XlwmsInventorySnapshot $snapshot): array
    {
        return ['snapshot_id' => $snapshot->id, 'status' => $snapshot->status, 'provider_sku_count' => $snapshot->provider_sku_count, 'mapped_sku_count' => $snapshot->mapped_sku_count, 'unmatched_sku_count' => $snapshot->unmatched_sku_count, 'blocked_sku_count' => $snapshot->blocked_sku_count, 'mismatches' => $snapshot->mismatches ?? [], 'fetched_at' => $snapshot->fetched_at?->toIso8601String(), 'last_checked_at' => $snapshot->updated_at?->toIso8601String(), 'oms_inventory_sku_count' => $snapshot->summary['oms_rows'] ?? null,
            'oms_good_qty' => $snapshot->summary['oms_good_qty'] ?? null,
            'oms_available_qty' => $snapshot->summary['oms_available_qty'] ?? null,
            'oms_locked_qty' => $snapshot->summary['oms_locked_qty'] ?? null,
            'changes' => $snapshot->summary['changes'] ?? [], 'lot_reconciliation_v2' => (bool) ($snapshot->summary['v2'] ?? false), 'dated_lot_reductions' => $snapshot->summary['dated_lot_reductions'] ?? [], 'unknown_age_quantities' => $snapshot->summary['unknown_age'] ?? [], 'checksum' => $snapshot->checksum];
    }
}
