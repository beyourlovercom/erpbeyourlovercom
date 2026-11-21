<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;
use App\Modules\Orders\Application\OrderManualHoldStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The MorePlus preview/confirm/batch lifecycle, scoped exclusively to DE.
 * R5-R6 is DEV SIMULATION ONLY. No OMS gateway or HTTP client is injected.
 */
final class DeTianyunOutboundBatchService
{
    public const MAX_ORDERS = 200;

    public function __construct(private readonly SingleOrderPreview $preflight) {}

    /** @param list<int> $orderIds
     * @return array<string,mixed>
     */
    public function preview(array $orderIds): array
    {
        $this->validateSelection($orderIds);
        $orders = Order::query()->eligibleForOperations()
            ->with(['address', 'items', 'shipment.warehouse',
                'shipment.carrierService', 'shipment.overseaReview'])
            ->whereIn('id', $orderIds)->get()->keyBy('id');
        $previousAttempts = XlwmsDispatchAttempt::query()
            ->whereIn('order_id', $orderIds)->pluck('order_id')->map(fn ($id) => (int) $id)->all();
        $alreadyAttempted = array_fill_keys($previousAttempts, true);
        sort($orderIds, SORT_NUMERIC);
        $items = [];
        foreach ($orderIds as $id) {
            $order = $orders->get($id);
            $blockers = [];
            $payloadHash = null;
            if (! $order || $order->shipment?->warehouse?->code !== 'DE') {
                $blockers[] = 'DE_ORDER_NOT_FOUND_OR_WRONG_WAREHOUSE';
            } else {
                if (! in_array($order->routing_warehouse_code, [null, 'DE'], true)) {
                    $blockers[] = 'DE_ORDER_ROUTE_CHANGED';
                }
                if (in_array((string) $order->workflow_status,
                    ['out_of_stock', 'pending_allocation', ...OrderManualHoldStatus::CODES], true)) {
                    $blockers[] = 'DE_ORDER_WORKFLOW_BLOCKED';
                }
                if (isset($alreadyAttempted[$id])) {
                    $blockers[] = 'DE_EXISTING_PROVIDER_ATTEMPT';
                }
                // A local simulation checks Product OS identity, approved review,
                // quantity and channel without claiming a fresh remote stock read.
                // A later real-create phase must require fresh applied OMS V2 proof.
                $check = $this->preflight->forOrder($order,
                    ! app()->environment('local', 'testing'), false, 'DE');
                $blockers = [...$blockers, ...$check['blockers']];
                $payloadHash = $check['payload_hash'];
            }
            $blockers = array_values(array_unique($blockers));
            $items[] = [
                'order_id' => $id,
                'order_no' => (string) ($order?->erp_order_id ?: $id),
                'payload_hash' => $payloadHash,
                'ready' => $blockers === [],
                'blockers' => $blockers,
            ];
        }
        $eligible = array_values(array_filter($items, static fn (array $item): bool => $item['ready']));

        return [
            'scope' => 'selected', 'scan_count' => count($orderIds),
            'count' => count($eligible), 'excluded_count' => count($orderIds) - count($eligible),
            'overflow' => false, 'dry_run' => true,
            'items' => $items,
            'fingerprint' => hash('sha256', json_encode([
                'warehouse' => 'DE', 'selected_order_ids' => $orderIds, 'items' => $items,
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    /** @param list<int> $orderIds */
    public function start(int $operatorId, string $expectedFingerprint, array $orderIds): string
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('DE_REAL_ORDER_CREATE_NOT_AUTHORIZED');
        }
        $mutex = Cache::lock('xlwms:de:batch:start', 12);
        if (! $mutex->get()) {
            throw new RuntimeException('DE_BATCH_LOCKED');
        }
        try {
            $preview = $this->preview($orderIds);
            if ($preview['count'] < 1
                || ! hash_equals($preview['fingerprint'], $expectedFingerprint)) {
                throw new RuntimeException('DE_BATCH_STALE');
            }
            if (DB::table('xlwms_de_dispatch_batches')
                ->whereIn('status', ['queued', 'running', 'paused'])->exists()) {
                throw new RuntimeException('DE_BATCH_ALREADY_RUNNING');
            }
            $id = (string) Str::uuid();
            $eligible = array_values(array_filter($preview['items'],
                static fn (array $item): bool => $item['ready']));
            DB::transaction(function () use ($id, $operatorId, $eligible): void {
                DB::table('xlwms_de_dispatch_batches')->insert([
                    'id' => $id, 'operator_user_id' => $operatorId, 'status' => 'queued',
                    'dry_run' => true, 'total' => count($eligible),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($eligible as $item) {
                    DB::table('xlwms_de_dispatch_batch_items')->insert([
                        'batch_id' => $id, 'order_id' => $item['order_id'],
                        'order_no' => $item['order_no'], 'payload_hash' => $item['payload_hash'],
                        'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            });

            return $id;
        } finally {
            $mutex->release();
        }
    }

    /** @return array<string,mixed> */
    public function status(string $batchId): array
    {
        $batch = DB::table('xlwms_de_dispatch_batches')->where('id', $batchId)->first();
        if (! $batch) {
            throw new RuntimeException('DE_BATCH_NOT_FOUND');
        }
        $items = DB::table('xlwms_de_dispatch_batch_items')
            ->where('batch_id', $batchId)->orderBy('id')
            ->get(['order_id', 'order_no', 'status', 'result_code']);

        return [
            'batch_id' => $batchId, 'status' => $batch->status,
            'dry_run' => true, 'total' => (int) $batch->total,
            'processed' => $items->whereNotIn('status', ['queued', 'processing'])->count(),
            'items' => $items->map(fn ($item) => (array) $item)->values()->all(),
        ];
    }

    /** @param list<int> $ids */
    private function validateSelection(array $ids): void
    {
        if ($ids === [] || count($ids) > self::MAX_ORDERS
            || count(array_unique($ids)) !== count($ids)) {
            throw new RuntimeException('DE_BATCH_SELECTION_INVALID');
        }
        foreach ($ids as $id) {
            if (! is_int($id) || $id < 1) {
                throw new RuntimeException('DE_BATCH_SELECTION_INVALID');
            }
        }
    }
}
