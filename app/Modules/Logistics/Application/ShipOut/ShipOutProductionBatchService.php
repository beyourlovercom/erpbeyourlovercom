<?php

namespace App\Modules\Logistics\Application\ShipOut;

use App\Modules\Logistics\Infrastructure\Models\Shipment;
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

    public function candidateIds(): array
    {
        $configuredCutover = (int) config('shipout.production_v1_min_order_id', 0);
        if (app()->environment('production') && $configuredCutover < 1) {
            return [];
        }
        $cutover = max(1, $configuredCutover);
        $approvedIds = app()->environment('production') ? self::productionApprovedOrderIds() : [];
        if (app()->environment('production') && $approvedIds === []) {
            // No live warehouse-wide enumeration without explicit exact IDs.
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
        if (app()->environment('production')) {
            $query->whereIn('order_id', $approvedIds);
        }

        return $query->orderBy('id')->limit(self::MAX_ORDERS + 1)
            ->pluck('order_id')->map(fn ($id) => (int) $id)->all();
    }

    public function preview(): array
    {
        $ids = $this->candidateIds();

        return [
            'count' => count($ids),
            'overflow' => count($ids) > self::MAX_ORDERS,
            'fingerprint' => hash('sha256', implode(',', $ids)),
            'dry_run' => ! app()->environment('production'),
        ];
    }

    public function start(int $operator, string $fingerprint): string
    {
        $lock = cache()->lock('shipout:moreplus:batch:start', 12);
        if (! $lock->get()) {
            throw new RuntimeException('SHIPOUT_BATCH_LOCKED');
        }
        try {
            $ids = $this->candidateIds();
            $preview = [
                'count' => count($ids),
                'overflow' => count($ids) > self::MAX_ORDERS,
                'fingerprint' => hash('sha256', implode(',', $ids)),
                'dry_run' => ! app()->environment('production'),
            ];
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
