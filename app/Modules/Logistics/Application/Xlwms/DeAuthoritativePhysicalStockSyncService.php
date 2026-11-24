<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsInventorySnapshot;
use App\Modules\Products\Application\VerifiedOmsProductIdentityResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * DE-only physical stock authority. Deliberately does NOT alter order reservations,
 * historical FIFO lots, aging accruals, outbound shipments, or WooCommerce.
 * A signed live OMS snapshot is obtained before every automatic apply.
 */
final class DeAuthoritativePhysicalStockSyncService
{
    public function __construct(
        private readonly XlwmsInventorySyncService $source,
        private readonly VerifiedOmsProductIdentityResolver $identity,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array<string,mixed> */
    public function run(bool $apply = false): array
    {
        if ($apply) $this->assertApplyEnabled();
        $lock = Cache::lock('xlwms:de:authoritative:physical-stock', 180);
        if (! $lock->get()) throw new RuntimeException('DE_PHYSICAL_SYNC_ALREADY_RUNNING');

        try {
            // The existing reader validates signed API responses, Hannover scope,
            // pagination, stock quality, SKU aliases and customer exclusions.
            $fresh = $this->source->sync(false, 'DE');
            return $this->reconcileSnapshot((int) ($fresh['snapshot_id'] ?? 0), $apply);
        } finally {
            $lock->release();
        }
    }

    /**
     * Applies the latest *just-observed* snapshot only. Public for isolated
     * integration tests and CLI preflight, never exposed as a web endpoint.
     *
     * @return array<string,mixed>
     */
    public function reconcileSnapshot(int $snapshotId, bool $apply = false): array
    {
        if ($apply) $this->assertApplyEnabled();
        return DB::transaction(function () use ($snapshotId, $apply): array {
            $warehouse = Warehouse::query()->where('code', 'DE')->lockForUpdate()->firstOrFail();
            $snapshot = XlwmsInventorySnapshot::query()
                ->where('warehouse_id', $warehouse->id)->whereKey($snapshotId)
                ->lockForUpdate()->firstOrFail();
            $latest = XlwmsInventorySnapshot::query()
                ->where('warehouse_id', $warehouse->id)
                ->orderByDesc('updated_at')->orderByDesc('id')->first();
            if (! $latest || (int) $latest->id !== $snapshotId
                || ! $snapshot->updated_at || $snapshot->updated_at->isFuture()
                || $snapshot->updated_at->diffInSeconds(now()) > 150
                || $snapshot->wh_code !== 'Hannover'
                || ! preg_match('/^[0-9a-f]{64}$/D', (string) $snapshot->checksum)) {
                throw new RuntimeException('DE_PHYSICAL_SNAPSHOT_NOT_FRESH_OR_WRONG_WAREHOUSE');
            }
            if (in_array($snapshot->status, ['not_ready', 'failed'], true)) {
                throw new RuntimeException('DE_PHYSICAL_PROVIDER_NOT_READY');
            }

            $rows = $snapshot->rows()->get();
            $excluded = array_fill_keys(array_map(
                static fn (string $s): string => mb_strtolower(trim($s), 'UTF-8'),
                (array) config('xlwms.de_excluded_inventory_skus', [])
            ), true);
            $provider = [];
            $skipped = [];
            $productIds = [];
            $catalogOnly = 0;
            foreach ($rows as $row) {
                $sku = trim((string) $row->provider_sku);
                if (isset($excluded[mb_strtolower($sku, 'UTF-8')])) {
                    // Never trust an accidental product_id on an excluded row.
                    $skipped[] = $sku;
                    continue;
                }
                if ($row->resolution_status === 'catalog_only' && $row->oms_total === null) {
                    $catalogOnly++;
                    continue;
                }
                if (! in_array($row->resolution_status, ['matched', 'blocked'], true)
                    || $row->oms_total === null || $row->oms_available === null
                    || $row->oms_locked === null || (int) $row->oms_total < 0
                    || (int) $row->oms_available < 0 || (int) $row->oms_locked < 0
                    || (int) $row->oms_total !== (int) $row->oms_available + (int) $row->oms_locked
                    || (int) $row->product_id <= 0) {
                    throw new RuntimeException('DE_PHYSICAL_SKU_NOT_VERIFIED:'.$sku);
                }
                $resolved = $this->identity->resolve($sku);
                if (($resolved['status'] ?? null) !== 'resolved'
                    || (int) $resolved['product_id'] !== (int) $row->product_id) {
                    throw new RuntimeException('DE_PHYSICAL_SKU_IDENTITY_CHANGED:'.$sku);
                }
                if (isset($productIds[$row->product_id])) {
                    throw new RuntimeException('DE_PHYSICAL_DUPLICATE_ERP_PRODUCT');
                }
                $productIds[$row->product_id] = true;
                $provider[] = $row;
            }
            if ($provider === [] || count($provider) !== (int) $snapshot->mapped_sku_count) {
                throw new RuntimeException('DE_PHYSICAL_INCOMPLETE_PROVIDER_COVERAGE');
            }

            $plan = [];
            foreach ($provider as $row) {
                $existing = WarehouseInventory::withTrashed()->where('warehouse_id', $warehouse->id)
                    ->where('product_id', $row->product_id)->lockForUpdate()->first();
                if ($existing?->trashed()) {
                    throw new RuntimeException('DE_PHYSICAL_STOCK_SOFT_DELETED:'.$row->provider_sku);
                }
                $before = $existing?->quantity_on_hand;
                $beforeAvailable = $existing?->provider_available_quantity;
                $plan[] = [
                    'sku' => $row->provider_sku,
                    'product_id' => (int) $row->product_id,
                    'oms_qoh' => (int) $row->oms_total,
                    'oms_available' => (int) $row->oms_available,
                    'before_qoh' => $before,
                    'before_available' => $beforeAvailable,
                    'reserved_kept' => (int) ($existing?->quantity_reserved ?? 0),
                    'changed' => $existing === null || (int) $before !== (int) $row->oms_total
                        || $beforeAvailable === null
                        || (int) $beforeAvailable !== (int) $row->oms_available,
                    'existing' => $existing,
                ];
            }
            $changes = array_sum(array_map(static fn (array $x): int => (int) $x['changed'], $plan));
            $result = [
                'warehouse' => 'DE', 'mode' => $apply ? 'apply' : 'dry_run',
                'snapshot_id' => $snapshotId, 'checksum' => $snapshot->checksum,
                'verified_skus' => count($provider), 'excluded_skus' => $skipped,
                'catalog_only_skus' => $catalogOnly, 'changed_skus' => $changes,
                'unchanged_skus' => count($provider) - $changes,
                'oms_total' => array_sum(array_map(static fn ($x): int => (int) $x->oms_total, $provider)),
                'historical_ledger_blockers' => (int) $snapshot->blocked_sku_count,
                'stock_updated' => false,
            ];
            if (! $apply) return $result;

            foreach ($plan as $item) {
                /** @var WarehouseInventory|null $inventory */
                $inventory = $item['existing'];
                $before = $inventory ? [
                    'quantity_on_hand' => (int) $inventory->quantity_on_hand,
                    'provider_available_quantity' => $inventory->provider_available_quantity,
                    'quantity_reserved' => (int) $inventory->quantity_reserved,
                ] : null;
                if (! $inventory) {
                    $inventory = WarehouseInventory::query()->create([
                        'warehouse_id' => $warehouse->id,
                        'product_id' => $item['product_id'],
                        'quantity_on_hand' => $item['oms_qoh'], 'quantity_reserved' => 0,
                        'expected_restock_qty' => 0,
                        'provider_available_quantity' => $item['oms_available'],
                        'provider_stock_synced_at' => now(),
                    ]);
                } elseif ($item['changed']) {
                    $inventory->forceFill([
                        'quantity_on_hand' => $item['oms_qoh'],
                        'provider_available_quantity' => $item['oms_available'],
                        'provider_stock_synced_at' => now(),
                    ])->save();
                }
                if (! $item['changed']) continue;
                DB::table('inventory_adjustments')->insert([
                    'warehouse_id' => $warehouse->id, 'product_id' => $item['product_id'],
                    'warehouse_inventory_id' => $inventory->id,
                    'quantity_before' => (int) ($item['before_qoh'] ?? 0),
                    'quantity_after' => $item['oms_qoh'],
                    'reserved_before' => $item['reserved_kept'],
                    'reserved_after' => $item['reserved_kept'],
                    'actor_user_id' => null,
                    'reason' => 'DE/Hannover OMS authoritative physical qty snapshot #'.$snapshotId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->audit->record(
                    AuditActor::system(AuditSource::InternalSystem),
                    'inventory.de_oms_authoritative_physical_updated',
                    WarehouseInventory::class, $inventory->id, $before,
                    [
                        'sku' => $item['sku'], 'qoh' => $item['oms_qoh'],
                        'provider_available' => $item['oms_available'],
                        'quantity_reserved_unchanged' => $item['reserved_kept'],
                        'snapshot_id' => $snapshotId, 'checksum' => $snapshot->checksum,
                    ], 'de_oms_is_authority_for_physical_stock',
                );
            }
            // Separate proof of *physical* apply; blocked historical lot aging
            // must not be reclassified as a fully reconciled FIFO/ledger apply.
            $summary = (array) ($snapshot->summary ?? []);
            $summary['de_authoritative_physical_v1'] = true;
            $summary['de_authoritative_physical_applied_at'] = now()->toIso8601String();
            $summary['de_authoritative_physical_sku_count'] = count($provider);
            $summary['de_authoritative_physical_change_count'] = $changes;
            $snapshot->forceFill(['summary' => $summary])->save();
            $result['stock_updated'] = true;
            return $result;
        }, 3);
    }

    private function assertApplyEnabled(): void
    {
        if (! app()->environment('production', 'local', 'testing')
            || ! (bool) config('xlwms.de_authoritative_auto_apply_enabled', false)) {
            throw new RuntimeException('DE_AUTHORITATIVE_PHYSICAL_APPLY_DISABLED');
        }
    }
}
