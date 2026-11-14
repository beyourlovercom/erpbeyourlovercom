<?php

namespace App\Modules\Logistics\Application\ShipOut;

use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Application\OrderManualHoldStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Snapshot only fully reviewed, currently unattempted MorePlus orders.
 * The job repeats production preflight for every order immediately before POST.
 */
final class ShipOutProductionBatchService
{
    public const MAX_ORDERS = 200;

    /**
     * Fail closed: production can act only on explicit exact order IDs.
     * The one-order Canary takes priority over the new finite multi-order list.
     *
     * @return list<int>
     */
    public static function productionApprovedOrderIds(): array
    {
        $canaryId = (int) config('shipout.production_v1_batch_canary_order_id', 0);
        if ($canaryId > 0) {
            return [$canaryId];
        }

        $raw = trim((string) config('shipout.production_v1_batch_approved_order_ids', ''));
        if ($raw === '' || preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/D', $raw) !== 1) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', explode(',', $raw))));
        if (count($ids) > self::MAX_ORDERS || in_array(0, $ids, true)) {
            return [];
        }

        return $ids;
    }

    /**
     * R14 Owner-authorized rollout: trusted operators can select actual
     * approved pending orders in the UI, without an engineer editing IDs.
     * Never override a nonzero Canary or a static finite allowlist.
     */
    public static function productionAllowsOperatorSelection(): bool
    {
        return app()->environment('production')
            && (bool) config('shipout.production_v1_batch_operator_selection_enabled', false)
            && (int) config('shipout.production_v1_batch_canary_order_id', 0) === 0
            && trim((string) config('shipout.production_v1_batch_approved_order_ids', '')) === ''
            && (int) config('shipout.production_v1_min_order_id', 0) > 0;
    }

    /** @param list<int>|null $selectedOrderIds */
    public function candidateIds(?array $selectedOrderIds = null): array
    {
        $this->validateSelection($selectedOrderIds);
        $configuredCutover = (int) config('shipout.production_v1_min_order_id', 0);
        if (app()->environment('production') && $configuredCutover < 1) {
            return [];
        }
        $cutover = max(1, $configuredCutover);
        $approvedIds = app()->environment('production') ? self::productionApprovedOrderIds() : [];
        if (app()->environment('production') && $approvedIds === []
            && ! self::productionAllowsOperatorSelection()) {
            // The broad UI mode must be explicitly owner-enabled; default
            // production remains blocked without exact IDs.
            return [];
        }

        $query = Shipment::query()
            ->where('shipment_status', 'pending')
            ->where('carrier', 'USPS')
            ->where(fn ($q) => $q->whereNull('tracking_number')->orWhere('tracking_number', ''))
            ->whereHas('warehouse', fn ($q) => $q->where('code', 'US-LA'))
            ->whereHas('overseaReview', fn ($q) => $q
                ->where('status', 'approved')
                ->whereColumn('reviewed_warehouse_id', 'shipments.warehouse_id'))
            ->whereHas('order', fn ($q) => $q->eligibleForOperations()
                ->where('order_status', 'pending_shipment')
                ->where(fn ($workflow) => $workflow->whereNull('workflow_status')
                    ->orWhereNotIn('workflow_status', ['out_of_stock', 'pending_allocation', ...OrderManualHoldStatus::CODES]))
                ->whereDoesntHave('operationalMarkers', fn ($markers) => $markers
                    ->whereIn('marker', ['stockout', 'domestic_stockout', ...OrderManualHoldStatus::HOLD_MARKERS]))
                ->whereDoesntHave('fulfillmentParent', fn ($parent) => $parent
                    ->whereIn('workflow_status', ['pending_allocation', 'out_of_stock', ...OrderManualHoldStatus::CODES])
                    ->orWhereHas('operationalMarkers', fn ($m) => $m
                        ->whereIn('marker', OrderManualHoldStatus::HOLD_MARKERS)))
                ->whereDoesntHave('externalReferences', fn ($references) => $references
                    ->where('provider', 'historical_logistics'))
                ->whereHas('address', fn ($address) => $address->where('country', 'US')))
            ->whereNotIn('order_id', DB::table('shipout_dispatch_attempts')->select('order_id'))
            // Batch-level quarantine also covers preflight failures without a POST.
            // Operators must resolve errors deliberately; a new batch never silently retries.
            ->whereNotIn('order_id', DB::table('shipout_production_batch_items')
                ->whereIn('status', [
                    'queued', 'processing', 'pending_oms', 'oms_visible_provisional',
                    'oms_visible', 'skipped', 'uncertain', 'failed', 'provider_cancelled', 'needs_review',
                ])->select('order_id'))
            ->where('order_id', '>=', $cutover);
        if (app()->environment('production') && $approvedIds !== []) {
            $query->whereIn('order_id', $approvedIds);
        }
        if ($selectedOrderIds !== null) {
            $query->whereIn('order_id', $selectedOrderIds);
        }

        return $query->orderBy('id')->limit(self::MAX_ORDERS + 1)
            ->pluck('order_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @param list<int>|null $selectedOrderIds */
    public function preview(?array $selectedOrderIds = null): array
    {
        return $this->snapshot($selectedOrderIds)['preview'];
    }

    /**
     * One read snapshot binds the fingerprint and the exact IDs to persist.
     * Never enumerate the candidates a second time between confirmation
     * validation and the batch insert.
     *
     * @param  list<int>|null  $selectedOrderIds
     * @return array{ids:list<int>,preview:array<string,mixed>}
     */
    private function snapshot(?array $selectedOrderIds): array
    {
        $ids = $this->candidateIds($selectedOrderIds);
        // ALL means all pending rows in this warehouse, including unreviewed
        // and out-of-stock rows in the scan. It does NOT grant POST permission
        // to the excluded rows. Every live order still passes per-order guards.
        // Match the existing MorePlus "待发货" tab exactly, NOT every
        // warehouse shipment whose raw status happens to be pending.
        // The latter also includes review and other queues (51 instead of
        // the actual 15 pending-tab orders in the Production readback).
        $pending = Shipment::query()->where('shipment_status', 'pending')
            ->whereHas('warehouse', fn ($q) => $q->where('code', 'US-LA'))
            ->whereHas('overseaReview', fn ($q) => $q->where('status', 'approved')
                ->whereColumn('reviewed_warehouse_id', 'shipments.warehouse_id'))
            ->whereHas('order', fn ($q) => $q->eligibleForOperations()
                ->where('order_status', '!=', 'cancelled')
                ->where(fn ($workflow) => $workflow->whereNull('workflow_status')
                    ->orWhereNotIn('workflow_status', ['pending_allocation', ...OrderManualHoldStatus::CODES]))
                ->whereDoesntHave('operationalMarkers', fn ($m) => $m
                    ->whereIn('marker', OrderManualHoldStatus::HOLD_MARKERS))
                ->whereDoesntHave('fulfillmentParent', fn ($parent) => $parent
                    ->whereIn('workflow_status', ['pending_allocation', 'out_of_stock', ...OrderManualHoldStatus::CODES])
                    ->orWhereHas('operationalMarkers', fn ($m) => $m
                        ->whereIn('marker', OrderManualHoldStatus::HOLD_MARKERS)))
                ->whereDoesntHave('externalReferences', fn ($references) => $references
                    ->where('provider', 'historical_logistics')))
            // R12: scan matches the actual pending tab, including ACK rows
            // awaiting OMS, but the dispatchable IDs still exclude attempts.
            ->whereNotExists(fn ($query) => ShipOutProductionDispatchState::scopeCreated($query))
            ->whereNotExists(fn ($query) => ShipOutProductionDispatchState::scopeOmsException($query))
            ->whereNotExists(fn ($query) => ShipOutProductionDispatchState::scopeBatchQuarantined($query))
            ->whereNotExists(function ($query): void {
                ShipOutProductionDispatchState::scopeAttempt($query);
                $query->where('shipout_attempt.status', '!=', 'acknowledged');
            });
        if ($selectedOrderIds !== null) {
            $pending->whereIn('order_id', $selectedOrderIds);
        }
        $scanned = $selectedOrderIds === null
            ? (clone $pending)->count()
            : count($selectedOrderIds);
        $stockouts = (clone $pending)
            ->whereHas('order', fn ($order) => $order
                ->where(fn ($query) => $query->where('workflow_status', 'out_of_stock')
                    ->orWhereHas('operationalMarkers', fn ($marker) => $marker
                        ->whereIn('marker', ['stockout', 'domestic_stockout']))))
            ->count();

        return [
            'ids' => $ids,
            'preview' => [
                'scope' => $selectedOrderIds === null ? 'all' : 'selected',
                'scan_count' => $scanned,
                'stockout_count' => $stockouts,
                'excluded_count' => max(0, $scanned - count($ids)),
                'count' => count($ids),
                'overflow' => count($ids) > self::MAX_ORDERS || $scanned > self::MAX_ORDERS,
                // Bind the confirmation to the exact scope, selected IDs and
                // full pending count. A new stockout record must invalidate an
                // earlier all-orders confirmation even if candidate IDs stay same.
                'fingerprint' => hash('sha256', json_encode([
                    'scope' => $selectedOrderIds === null ? 'all' : 'selected',
                    'selected_ids' => $selectedOrderIds,
                    'scanned' => $scanned,
                    'candidate_ids' => $ids,
                ], JSON_THROW_ON_ERROR)),
                'dry_run' => ! app()->environment('production'),
            ],
        ];
    }

    /** @param list<int>|null $selectedOrderIds */
    public function start(int $operator, string $fingerprint, ?array $selectedOrderIds = null): string
    {
        $lock = cache()->lock('shipout:moreplus:batch:start', 12);
        if (! $lock->get()) {
            throw new RuntimeException('SHIPOUT_BATCH_LOCKED');
        }
        try {
            $snapshot = $this->snapshot($selectedOrderIds);
            $ids = $snapshot['ids'];
            $preview = $snapshot['preview'];
            if ($preview['overflow'] || $preview['count'] === 0
                || ! hash_equals($preview['fingerprint'], $fingerprint)) {
                throw new RuntimeException('SHIPOUT_BATCH_STALE');
            }
            if (DB::table('shipout_production_batches')->whereIn('status', ['queued', 'running', 'awaiting_oms', 'paused'])->exists()) {
                throw new RuntimeException('SHIPOUT_BATCH_ALREADY_RUNNING');
            }
            $id = (string) Str::uuid();
            DB::transaction(function () use ($id, $operator, $preview, $ids): void {
                DB::table('shipout_production_batches')->insert([
                    'id' => $id,
                    'operator_user_id' => $operator,
                    'status' => 'queued',
                    'dry_run' => $preview['dry_run'],
                    'total' => $preview['count'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $rows = [];
                foreach ($ids as $orderId) {
                    $order = DB::table('orders')->where('id', $orderId)->first(['erp_order_id']);
                    $rows[] = [
                        'batch_id' => $id, 'order_id' => $orderId,
                        'order_no' => (string) ($order?->erp_order_id ?? $orderId),
                        'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
                    ];
                }
                if (count($rows) !== $preview['count']) {
                    throw new RuntimeException('SHIPOUT_BATCH_STALE');
                }
                DB::table('shipout_production_batch_items')->insert($rows);
            });

            return $id;
        } finally {
            $lock->release();
        }
    }

    /** @param list<int>|null $selectedOrderIds */
    private function validateSelection(?array $selectedOrderIds): void
    {
        if ($selectedOrderIds === null) {
            return;
        }
        if ($selectedOrderIds === [] || count($selectedOrderIds) > self::MAX_ORDERS
            || count(array_unique($selectedOrderIds)) !== count($selectedOrderIds)) {
            throw new RuntimeException('SHIPOUT_BATCH_SELECTION_INVALID');
        }
        foreach ($selectedOrderIds as $id) {
            if (! is_int($id) || $id < 1) {
                throw new RuntimeException('SHIPOUT_BATCH_SELECTION_INVALID');
            }
        }
    }

    public function status(string $batchId): array
    {
        $batch = DB::table('shipout_production_batches')->where('id', $batchId)->first();
        if (! $batch) {
            throw new RuntimeException('SHIPOUT_BATCH_NOT_FOUND');
        }
        $items = DB::table('shipout_production_batch_items')->where('batch_id', $batchId)
            ->orderBy('id')->get(['order_id', 'order_no', 'status', 'result_code', 'provider_order_id']);

        return [
            'batch_id' => $batch->id,
            'status' => $batch->status,
            'dry_run' => (bool) $batch->dry_run,
            'total' => $batch->total,
            // Processed means readback completed or deliberately blocked;
            // async ACK and provisional OMS visibility are not final.
            'processed' => $items->whereNotIn('status', [
                'queued', 'processing', 'pending_oms', 'oms_visible_provisional',
            ])->count(),
            'items' => $items,
        ];
    }
}
