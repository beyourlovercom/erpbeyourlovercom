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
use App\Modules\Products\Application\VerifiedOmsProductIdentityResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class XlwmsInventorySyncService
{
    private readonly XlwmsLotReconciliationService $lotReconciliation;

    public function __construct(private readonly XlwmsInventoryGateway $gateway, private readonly VerifiedOmsProductIdentityResolver $resolver, private readonly XlwmsInventoryPageParser $parser, private readonly AuditRecorder $audit, ?XlwmsLotReconciliationService $lotReconciliation = null)
    {
        $this->lotReconciliation = $lotReconciliation ?? new XlwmsLotReconciliationService();
    }

    /** @return array<string,mixed> */
    public function sync(bool $apply = false, string $warehouseCode = 'US-SM', array $resumeVerifiedSkus = []): array
    {
        if (! in_array($warehouseCode, ['US-SM', 'DE', 'US-NY'], true)) throw new RuntimeException('XLWMS_WAREHOUSE_UNSUPPORTED');
        if ($warehouseCode === 'US-NY' && $apply) {
            if (! app()->environment('local', 'testing')
                || ! (bool) config('xlwms.us_ny_inventory_dev_apply_enabled', false)) {
                throw new RuntimeException('XLWMS_US_NY_APPLY_NOT_AUTHORIZED');
            }
            if (! $this->v2Enabled() || ! $this->skuIsolationEnabled()) {
                throw new RuntimeException('XLWMS_US_NY_REQUIRES_V2_SKU_ISOLATION');
            }
        }
        if ($warehouseCode !== 'DE' && $resumeVerifiedSkus !== []) throw new RuntimeException('XLWMS_RESUME_ONLY_SUPPORTED_FOR_DE');
        // R4 permits DE inventory writes ONLY in isolated Local/Testing, with a
        // separate DE gate. V2 + per-SKU quarantine and first-checksum approval
        // remain mandatory; this cannot enable Production or US-SM dispatch.
        if ($warehouseCode === 'DE' && $apply) {
            if (! (
                (app()->environment('local', 'testing')
                    && (bool) config('xlwms.de_inventory_dev_apply_enabled', false))
                || (app()->environment('production')
                    && (bool) config('xlwms.de_inventory_production_apply_enabled', false))
            )) {
                throw new RuntimeException('XLWMS_DE_INVENTORY_APPLY_NOT_AUTHORIZED');
            }
            if (! $this->v2Enabled() || ! $this->skuIsolationEnabled()) {
                throw new RuntimeException('XLWMS_DE_REQUIRES_V2_SKU_ISOLATION');
            }
        }
        if ($apply && ! (bool) config('xlwms.inventory_apply_enabled', false)) throw new RuntimeException('XLWMS_INVENTORY_APPLY_DISABLED');
        $warehouse = Warehouse::query()->where('code', $warehouseCode)->firstOrFail();
        $connection = WarehouseOpenApiConnection::query()->where('warehouse_id', $warehouse->id)->where('provider', 'xlwms')->firstOrFail();
        $whCode = trim((string) $connection->oms_warehouse_code);
        if ($whCode === '') throw new RuntimeException('OMS_WAREHOUSE_CODE_NOT_CONFIGURED');
        if ($warehouseCode === 'DE' && $whCode !== 'Hannover') throw new RuntimeException('OMS_DE_WAREHOUSE_CODE_MISMATCH');
        if ($warehouseCode === 'US-NY' && $whCode !== 'US01') throw new RuntimeException('OMS_US_NY_WAREHOUSE_CODE_MISMATCH');
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

        // Only DE owns these explicit customer-scope exclusions. Keep source
        // observations for auditing, but exclude them before identity resolution,
        // quantity reconciliation and any potential apply.
        $deExcluded = [];
        if ($warehouseCode === 'DE') {
            foreach ((array) config('xlwms.de_excluded_inventory_skus', []) as $excludedSku) {
                $deExcluded[mb_strtolower(trim((string) $excludedSku), 'UTF-8')] = true;
            }
        }
        $rows = []; $mismatches = []; $mapped = $unmatched = $blocked = 0;
        // A catalog SKU without a stock-ledger row is UNKNOWN, never implicit zero.
        // Only current, warehouse-scoped stock rows may drive inventory updates.
        foreach (array_keys($normalized) as $sku) {
            if (isset($deExcluded[mb_strtolower($sku, 'UTF-8')])) {
                $rows[] = ['sku' => $sku, 'status' => 'excluded',
                    'stock' => $normalized[$sku], 'reason' => 'OMS_SKU_EXCLUDED_OTHER_CUSTOMER'];
                continue;
            }
            $resolution = $this->resolveWarehouseSku($warehouse, $sku);
            $resolved = $resolution['status'] === 'resolved' ? $resolution['product_id'] : null;
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
            // OMS locked = stock committed to upcoming warehouse shipments. It is
            // not a local ERP reservation and MUST NOT be copied or reconciled to
            // warehouse_inventory.quantity_reserved. Physical QOH includes locked.
            if ($stock !== null && (int) ($existingInventory?->quantity_reserved ?? 0) > $stock['total']) {
                $reason = 'ERP_LOCAL_RESERVATIONS_EXCEED_OMS_PHYSICAL_QOH';
            }
            if ($stock !== null && ! $this->v2Enabled() && $this->hasProtectedState($warehouse->id, (int) $resolved)) $reason = 'LOT_RECONCILIATION_REQUIRED';
            if ($reason !== null) { $blocked++; $mismatches[] = ['sku' => $sku, 'reason' => $reason]; }
            $rows[] = ['sku' => $sku, 'status' => $reason ? 'blocked' : 'matched', 'product_id' => (int) $resolved, 'stock' => $stock, 'reason' => $reason];
        }
        // Persist all OMS catalog SKUs, including SKUs with no ledger in this warehouse.
        // Catalog-only is deliberately not "unmatched" and never means stock zero.
        foreach (array_keys($providerSkus) as $sku) {
            if (isset($normalized[$sku])) continue;
            if (isset($deExcluded[mb_strtolower($sku, 'UTF-8')])) {
                $rows[] = ['sku' => $sku, 'status' => 'excluded',
                    'reason' => 'OMS_SKU_EXCLUDED_OTHER_CUSTOMER'];
                continue;
            }
            $resolution = $this->resolveWarehouseSku($warehouse, $sku);
            $catalogProductId = $resolution['status'] === 'resolved' ? $resolution['product_id'] : null;
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
        $checksum = hash('sha256', json_encode(['snapshot_schema' => $this->v2Enabled() ? ($this->skuIsolationEnabled() ? 7 : 5) : 2,
            'sku_isolation' => $this->skuIsolationEnabled(), 'reconciliation_mode' => $this->v2Enabled() ? 'lot_v2' : 'quantity_v1', 'wh_code' => $whCode, 'catalog_skus' => array_keys($providerSkus), 'inventory' => $normalized, 'local_baseline' => $localBaseline, 'lot_baseline' => $this->v2LotBaseline($warehouse, $rows), 'p9_consumption_baseline' => $this->v2P9ConsumptionBaseline($warehouse, $rows), 'unknown_age_baseline' => $this->v2UnknownAgeBaseline($warehouse, $rows)], JSON_THROW_ON_ERROR));
        $status = ($unmatched > 0 || $blocked > 0) ? 'blocked' : 'read_only';
        $snapshot = $this->persistAndMaybeApply($warehouse, $whCode, $checksum, $providerSkus, $normalized, $rows, $mismatches, $mapped, $unmatched, $blocked, $status, $apply, $localBaseline, $resumeVerifiedSkus);
        $this->audit->record(AuditActor::system(AuditSource::InternalSystem), 'inventory.xlwms_snapshot_'.$snapshot->status, $snapshot::class, $snapshot->id, after: $this->result($snapshot), reason: 'xlwms_inventory_sync');
        return $this->result($snapshot);
    }

    /** NY must use an active, verified warehouse mapping, never a global alias. */
    public function resolveWarehouseSku(Warehouse $warehouse, string $sku): array
    {
        if ($warehouse->code !== 'US-NY') return $this->resolver->resolve($sku);
        $matches = \App\Modules\Products\Infrastructure\Models\ProductWarehouseSku::query()
            ->where('warehouse_id', $warehouse->id)->where('is_active', true)
            ->whereNotNull('verified_at')->whereRaw('LOWER(sku) = ?', [mb_strtolower($sku)])
            ->whereHas('product', fn ($query) => $query->where('is_active', true))
            ->get()->filter(fn ($mapping) => strcasecmp(trim((string) $mapping->sku), $sku) === 0);
        return $matches->count() === 1
            ? ['status' => 'resolved', 'product_id' => (int) $matches->first()->product_id]
            : ['status' => 'blocked', 'product_id' => null];
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

    private function persistAndMaybeApply(Warehouse $warehouse, string $whCode, string $checksum, array $providerSkus, array $normalized, array $rows, array $mismatches, int $mapped, int $unmatched, int $blocked, string $status, bool $apply, array $localBaseline, array $resumeVerifiedSkus = []): XlwmsInventorySnapshot
    {
        return DB::transaction(function () use ($warehouse, $whCode, $checksum, $providerSkus, $normalized, $rows, $mismatches, $mapped, $unmatched, $blocked, $status, $apply, $localBaseline, $resumeVerifiedSkus): XlwmsInventorySnapshot {
            if ($this->v2Enabled()) {
                if ($this->skuIsolationEnabled()) {
                    return $this->persistSkuIsolatedV2($warehouse, $whCode, $checksum,
                        $providerSkus, $normalized, $rows, $mismatches, $mapped,
                        $unmatched, $blocked, $apply, $localBaseline, $resumeVerifiedSkus);
                }
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
                if ($snapshot?->status === 'applied') {
                    // Each successful full OMS re-fetch, including unchanged
                    // stock, is a NEW freshness observation. A simple touch()
                    // only updates updated_at; the outbound preflight reads
                    // fetched_at and would otherwise be stale indefinitely.
                    // Never refresh an applied snapshot after a reconciliation
                    // blocker is detected, even when checksum still matches.
                    if ($allMismatches === []) {
                        $snapshot->forceFill(['fetched_at' => now()])->save();
                    }
                    return $snapshot->fresh();
                }
                if ($snapshot !== null && ! $apply) {
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

    /**
     * A full provider read is mandatory; ONLY product-local errors are
     * isolated. Unknown identity, FIFO conflict or an unverified SKU never
     * authorizes that SKU. No partial OMS writes or partial order dispatch.
     *
     * Runs under the outer database transaction in persistAndMaybeApply().
     */
    private function persistSkuIsolatedV2(
        Warehouse $warehouse, string $whCode, string $checksum,
        array $providerSkus, array $normalized, array $rows, array $mismatches,
        int $mapped, int $unmatched, int $blocked, bool $apply, array $localBaseline, array $resumeVerifiedSkus = []
    ): XlwmsInventorySnapshot {
        $plan = $this->lotReconciliation->plan($warehouse, $rows, $localBaseline);
        $issues = [...$mismatches, ...$plan['blocked']];
        if ($unmatched > 0) {
            $issues[] = ['reason' => 'OMS_UNMATCHED_STOCK_SKUS', 'count' => $unmatched];
        }

        $blockedIds = [];
        foreach ($issues as $issue) {
            if (isset($issue['product_id'])) $blockedIds[(int) $issue['product_id']] = true;
        }
        $byProduct = collect($rows)
            ->filter(fn (array $row): bool => ($row['status'] ?? null) === 'matched'
                && isset($row['product_id'], $row['stock']['total']))
            ->groupBy('product_id');
        foreach ($byProduct as $id => $matches) {
            if ($matches->count() < 2) continue;
            $blockedIds[(int) $id] = true;
            $issues[] = ['reason' => 'OMS_MULTIPLE_SKUS_ONE_ERP_PRODUCT',
                'product_id' => (int) $id,
                'skus' => $matches->pluck('sku')->values()->all()];
        }
        $eligible = array_filter($plan['products'],
            static fn (array $product): bool => ! isset($blockedIds[(int) $product['product_id']]));
        ksort($eligible, SORT_NUMERIC);

        // Resume is a full fresh provider read, but a SKU whose previous
        // write was verified must never be written again by "continue".
        // If provider stock changed after the prior success, quarantine it:
        // only a new "update sync" may update an already-completed SKU.
        if ($apply && $warehouse->code === 'DE' && $resumeVerifiedSkus !== []) {
            $previouslyVerified = array_fill_keys($resumeVerifiedSkus, true);
            foreach ($eligible as $id => $product) {
                if (! isset($previouslyVerified[(string) $product['sku']])) continue;
                unset($eligible[$id]);
                if ((int) $product['qoh_before'] !== (int) $product['qoh_after']) {
                    $issues[] = ['reason' => 'OMS_RESUME_PREVIOUS_SUCCESS_STOCK_CHANGED',
                        'product_id' => (int) $id, 'sku' => (string) $product['sku']];
                }
            }
        }

        // Automatic changes are bounded per SKU. An excessive delta blocks
        // only that SKU, not the other independently verified stock.
        $skuLimit = max(0, (int) config('xlwms.inventory_auto_max_sku_delta_units', 5));
        $totalLimit = max(0, (int) config('xlwms.inventory_auto_max_total_absolute_delta_units', 15));
        $totalUsed = 0;
        foreach ($eligible as $id => $product) {
            $delta = abs((int) $product['qoh_after'] - (int) $product['qoh_before']);
            if ($delta > $skuLimit || $totalUsed + $delta > $totalLimit) {
                unset($eligible[$id]);
                $issues[] = ['reason' => $delta > $skuLimit
                    ? 'OMS_SKU_DELTA_EXCEEDS_AUTO_LIMIT' : 'OMS_SKU_DEFERRED_BY_APPLY_BUDGET',
                    'product_id' => (int) $id, 'sku' => $product['sku'],
                    'absolute_delta' => $delta];
            } else {
                $totalUsed += $delta;
            }
        }

        // Every new identity must already be verified by Product OS. If a
        // previously applied SKU changes its canonical identity, isolate it.
        $last = XlwmsInventorySnapshot::query()
            ->where('warehouse_id', $warehouse->id)
            ->whereIn('status', ['applied', 'partial_applied'])
            ->orderByDesc('id')->first();
        // Never inherit an old V1/V2 approval or an untrusted status written
        // by a read-only observation. Only a real guarded SKU-level apply
        // provides an acceptable provenance for subsequent SKU reconciliation.
        if ($last !== null && (($last->summary['sku_isolation_write_proof'] ?? false) !== true
            || ($last->summary['sku_isolation'] ?? false) !== true
            || ($last->summary['v2_applied'] ?? false) !== true)) {
            $last = null;
        }
        if ($last !== null) {
            $priorIds = $last->status === 'partial_applied'
                ? array_map('intval', (array) ($last->summary['applied_product_ids'] ?? []))
                : null;
            $priorRows = $last->rows()->where('resolution_status', 'matched')
                ->whereNotNull('product_id')->get(['provider_sku', 'product_id'])
                ->filter(fn ($r): bool => $priorIds === null
                    || in_array((int) $r->product_id, $priorIds, true));
            foreach ($eligible as $id => $product) {
                $sku = (string) $product['sku'];
                $changed = $priorRows->contains(fn ($r): bool =>
                    ($r->provider_sku === $sku && (int) $r->product_id !== (int) $id)
                    || ((int) $r->product_id === (int) $id && $r->provider_sku !== $sku));
                if ($changed) {
                    unset($eligible[$id]);
                    $issues[] = ['reason' => 'OMS_SKU_IDENTITY_CHANGED',
                        'product_id' => (int) $id, 'sku' => $sku];
                }
            }
        }

        // A read-only sync may record evidence, but MUST NOT certify stock
        // or carry forward prior write authorization as a new applied status.
        // Fresh snapshots require an actual guarded sync(true) transaction.
        $snapshot = XlwmsInventorySnapshot::query()->where('warehouse_id', $warehouse->id)
            ->where('checksum', $checksum)->lockForUpdate()->first();
        if ($snapshot !== null
            && in_array($snapshot->status, ['applied', 'partial_applied'], true)
            && ($snapshot->summary['sku_isolation_write_proof'] ?? false) === true) {
            // Same signed full provider read + immutable local checksum:
            // update observation freshness without repeating the write.
            $snapshot->forceFill(['fetched_at' => now()])->save();
            return $snapshot->fresh();
        }
        if ($snapshot === null) {
            $snapshot = $this->storeSnapshot($warehouse, $whCode, $checksum, $providerSkus,
                $normalized, $rows, $issues, $mapped, $unmatched, count($issues),
                $issues === [] ? 'read_only' : 'blocked', false, [],
                [...$plan['summary'], 'sku_isolation' => true,
                    'eligible_product_ids' => array_map('intval', array_keys($eligible))]);
        }
        if (! $apply) {
            // An unchanged full signed DE read is a NEW observation. Preserve
            // the separate physical apply proof on the same snapshot row, but
            // update freshness for the next actual warehouse dispatch preflight.
            if (in_array($warehouse->code, ['DE', 'US-NY'], true)) {
                $snapshot->forceFill(['fetched_at' => now()])->save();
            }
            return $snapshot->fresh();
        }

        // The first V2 apply still requires the exact full OMS checksum and
        // Owner-approved config. Never use a prior V1 snapshot as approval.
        if ($last === null || ! (bool) ($last->summary['v2'] ?? false)) {
            // DE approval is warehouse-specific: its Owner-reviewed checksum can
            // never replace or accidentally inherit the US-SM first-apply gate.
            $approvedConfigKey = match ($warehouse->code) {
                'DE' => 'xlwms.de_inventory_first_apply_approved_checksum',
                'US-NY' => 'xlwms.us_ny_inventory_first_apply_approved_checksum',
                default => 'xlwms.inventory_first_apply_approved_checksum',
            };
            $approved = trim((string) config($approvedConfigKey, ''));
            if (strlen($approved) !== 64 || ! ctype_xdigit($approved)
                || ! hash_equals(strtolower($approved), strtolower($checksum))) {
                $issues[] = ['reason' => 'FIRST_V2_APPLY_EXACT_SNAPSHOT_APPROVAL_REQUIRED'];
                $eligible = [];
            }
        }
        if ($eligible === []) {
            $snapshot->forceFill(['status' => 'blocked',
                'blocked_sku_count' => count($issues), 'mismatches' => $issues])->save();
            return $snapshot->fresh();
        }

        $partialPlan = ['blocked' => [], 'products' => $eligible];
        $this->lotReconciliation->apply($warehouse, $partialPlan, (int) $snapshot->id, $checksum);

        $appliedIds = array_map('intval', array_keys($eligible));
        $allMatchedIds = array_map('intval', array_keys($plan['products']));
        $fullyApplied = $issues === [] && count($appliedIds) === count($allMatchedIds);
        $snapshot->forceFill([
            'status' => $fullyApplied ? 'applied' : 'partial_applied',
            'applied_at' => now(), 'blocked_sku_count' => count($issues),
            'mismatches' => $issues,
            'summary' => [...(array) $snapshot->summary,
                'v2' => true, 'v2_applied' => true, 'sku_isolation' => true,
                // Only set AFTER lotReconciliation->apply() succeeded inside
                // this locked transaction. Read-only operations can never set it.
                'sku_isolation_write_proof' => true,
                'applied_product_ids' => $appliedIds,
                'quarantined_sku_count' => count($issues)],
        ])->save();
        return $snapshot->fresh();
    }

    private function storeSnapshot(Warehouse $warehouse, string $whCode, string $checksum, array $providerSkus, array $normalized, array $rows, array $mismatches, int $mapped, int $unmatched, int $blocked, string $status, bool $applied, array $errors, array $extraSummary = []): XlwmsInventorySnapshot
    {
        $excludedStock = array_values(array_filter($rows,
            static fn (array $row): bool => $row['status'] === 'excluded' && isset($row['stock'])));
        $excludedKeys = array_fill_keys(array_column($excludedStock, 'sku'), true);
        $eligibleNormalized = array_filter($normalized,
            static fn (string $sku): bool => ! isset($excludedKeys[$sku]), ARRAY_FILTER_USE_KEY);
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
        $snapshot = XlwmsInventorySnapshot::query()->create(['warehouse_id' => $warehouse->id, 'wh_code' => $whCode, 'checksum' => $checksum, 'status' => $status, 'fetched_at' => now(), 'applied_at' => $applied ? now() : null, 'provider_sku_count' => count($providerSkus), 'mapped_sku_count' => $mapped, 'unmatched_sku_count' => $unmatched, 'blocked_sku_count' => $blocked, 'summary' => ['oms_rows' => count($eligibleNormalized),
                    'oms_good_qty' => array_sum(array_column($eligibleNormalized, 'total')),
                    'oms_available_qty' => array_sum(array_column($eligibleNormalized, 'available')),
                    'oms_locked_qty' => array_sum(array_column($eligibleNormalized, 'locked')),
                    'in_transit' => array_sum(array_column($eligibleNormalized, 'in_transit')),
                    'oms_raw_stock_sku_count' => count($normalized),
                    'oms_excluded_stock_sku_count' => count($excludedStock),
                    'oms_excluded_stock_qty' => array_sum(array_map(static fn (array $row): int => (int) $row['stock']['total'], $excludedStock)),
                    'changes' => $changes, ...$extraSummary], 'mismatches' => $mismatches, 'errors' => $errors]);
        foreach ($rows as $row) XlwmsInventorySnapshotRow::query()->create(['snapshot_id' => $snapshot->id, 'provider_sku' => $row['sku'], 'product_id' => $row['product_id'] ?? null, 'resolution_status' => $row['status'], 'oms_total' => $row['stock']['total'] ?? null, 'oms_available' => $row['stock']['available'] ?? null, 'oms_locked' => $row['stock']['locked'] ?? null, 'oms_in_transit' => $row['stock']['in_transit'] ?? null, 'mismatch_reason' => $row['reason'] ?? null]);
        return $snapshot;
    }

    /**
     * Physical identity approval belongs to Product OS unified SKU Alias.
     * A legacy smgj_pi import proves lineage but not same-product identity.
     * Before the Product OS verification migration, all aliases fail closed.
     */
    private function hasProtectedState(int $warehouseId, int $productId): bool
    {
        return WarehouseInventory::query()->where('warehouse_id', $warehouseId)->where('product_id', $productId)->whereNotNull('inbound_at')->exists() || WarehouseInventoryLot::query()->where('warehouse_id', $warehouseId)->where('product_id', $productId)->exists() || WarehouseInventoryLotConsumption::query()->where('warehouse_id', $warehouseId)->where('product_id', $productId)->exists();
    }

    private function v2Enabled(): bool
    {
        return (bool) config('xlwms.lot_reconciliation_v2_enabled', false);
    }

    private function skuIsolationEnabled(): bool
    {
        return $this->v2Enabled()
            && (bool) config('inventory_sku_gate.enabled', false);
    }

    /** @return array<int,array<string,mixed>> */
    private function v2LotBaseline(Warehouse $warehouse, array $rows): array
    {
        if (! $this->v2Enabled() || ! \Illuminate\Support\Facades\Schema::hasTable('warehouse_inventory_lots')) return [];
        $ids = collect($rows)->pluck('product_id')->filter()->map(fn ($id): int => (int) $id)->unique();
        return WarehouseInventoryLot::query()->where('warehouse_id', $warehouse->id)->whereIn('product_id', $ids)->orderBy('product_id')->orderBy('putaway_date')->orderBy('id')->get()->map(fn (WarehouseInventoryLot $lot): array => ['id' => (int) $lot->id, 'product_id' => (int) $lot->product_id, 'status' => $lot->stock_status, 'putaway_date' => $lot->putaway_date?->toDateString(), 'quantity' => (int) $lot->quantity, 'sync_batch_id' => $lot->sync_batch_id])->all();
    }

    /**
     * The P9 ledger is an independent part of physical FIFO state.
     * Source-lot gross quantity does NOT change when P9 records a shipment.
     * Include historical consumption in the snapshot identity, otherwise
     * fresh OMS reads incorrectly reuse a blocked/stale v4 snapshot after a
     * new real fulfillment or a corrected P9 source is recorded.
     *
     * @return list<array<string,int|string|null>>
     */
    private function v2P9ConsumptionBaseline(Warehouse $warehouse, array $rows): array
    {
        if (! $this->v2Enabled()
            || ! Schema::hasTable('warehouse_inventory_lot_consumptions')) {
            return [];
        }

        $productIds = collect($rows)->pluck('product_id')
            ->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
        if ($productIds === []) {
            return [];
        }

        return DB::table('warehouse_inventory_lot_consumptions')
            ->where('warehouse_id', $warehouse->id)
            ->whereIn('product_id', $productIds)
            ->orderBy('product_id')->orderBy('id')
            ->get(['id', 'product_id', 'source_inventory_lot_id', 'source_sync_batch_id',
                'stock_status', 'putaway_date', 'quantity', 'consumed_at'])
            ->map(static fn ($entry): array => [
                'id' => (int) $entry->id,
                'product_id' => (int) $entry->product_id,
                'source_inventory_lot_id' => $entry->source_inventory_lot_id === null
                    ? null : (int) $entry->source_inventory_lot_id,
                'source_sync_batch_id' => $entry->source_sync_batch_id === null
                    ? null : (int) $entry->source_sync_batch_id,
                'stock_status' => (string) $entry->stock_status,
                'putaway_date' => (string) $entry->putaway_date,
                'quantity' => (int) $entry->quantity,
                'consumed_at' => (string) $entry->consumed_at,
            ])->all();
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
