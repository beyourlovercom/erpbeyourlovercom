<?php

namespace App\Modules\Sync\Application;

use App\Modules\Logistics\Application\LogisticsTrackingListStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Full-order, read-only progress classification snapshot used only by the
 * Sync Grid status filter. The unfiltered page keeps its existing live status
 * projection. Never infer successful website synchronization from a bare
 * local "completed" label.
 *
 * Only orders with shipments or explicit manual-sync records can have a
 * non-default progress; all other ERP orders are conservatively "unsynced".
 */
final class OrderSyncProgressSnapshot
{
    private const CACHE_MINUTES = 18;

    private const MAX_AGE_MINUTES = 12;

    /**
     * @return array{generated_at:string,total:int,candidates:int,remote_configured:bool,non_unsynced:array<int,string>}
     */
    public function snapshot(): array
    {
        // Never build the full remote Woo snapshot in an employee HTTP
        // request. Scheduled/CLI refresh is the only permitted builder.
        $value = Cache::get($this->cacheKey());
        if (! is_array($value) || ! isset($value['generated_at'], $value['non_unsynced'])
            || ! is_array($value['non_unsynced'])
            || now()->diffInSeconds(CarbonImmutable::parse($value['generated_at']), false) < -self::MAX_AGE_MINUTES * 60) {
            throw new RuntimeException('同步状态全库索引尚未就绪或已过期，请在后台重新核验');
        }

        return $value;
    }

    /**
     * Read-only but potentially slow; MUST run via scheduled/CLI command,
     * never from an HTTP request. Redis lock avoids duplicate Woo batch reads.
     *
     * @return array{generated_at:string,total:int,candidates:int,remote_configured:bool,non_unsynced:array<int,string>}
     */
    public function refresh(): array
    {
        $lock = Cache::lock($this->cacheKey().':refresh-lock', 240);
        if (! $lock->get()) {
            throw new RuntimeException('已有全库状态核验任务执行中');
        }

        try {
            $snapshot = $this->build();
            Cache::put($this->cacheKey(), $snapshot, now()->addMinutes(self::CACHE_MINUTES));

            return $snapshot;
        } finally {
            $lock->release();
        }
    }

    private function cacheKey(): string
    {
        $connection = DB::connection();
        $identity = $connection->getName().':'.(string) $connection->getDatabaseName().':'.(string) config('app.url');

        return 'erp:sync:orders:progress-full-snapshot:v3:'.sha1($identity);
    }

    /**
     * @return array{generated_at:string,total:int,candidates:int,remote_configured:bool,non_unsynced:array<int,string>}
     */
    private function build(): array
    {
        $started = microtime(true);
        $gateway = app(Contracts\WooCommerceTrackingGateway::class);
        $remoteConfigured = $gateway instanceof Contracts\WooCommerceTrackingBatchInspector
            && ($gateway->descriptor()['configured'] ?? false) === true;

        // Match the visible active order queue, including for cached totals.
        // Never include archived/soft-deleted orders in the status index.
        $total = Order::query()->eligibleForOperations()->count();
        $candidates = 0;
        $nonUnsynced = [];
        $tracker = app(LogisticsTrackingListStatus::class);
        $projector = app(OrderSyncProgressProjector::class);

        Order::query()->eligibleForOperations()
            ->where(fn (Builder $query): Builder => $query
                ->whereHas('shipment')
                ->orWhereHas('manualSync'))
            ->with([
                'shipment', 'manualSync', 'operationalMarkers',
                'fulfillmentParent' => fn ($query) => $query->withTrashed(),
            ])
            ->chunkById(100, function ($orders) use ($tracker, $projector, $remoteConfigured, &$nonUnsynced, &$candidates, $started): void {
                if (microtime(true) - $started > 150) {
                    throw new RuntimeException('后台全库核验超过 150 秒安全限制，本轮索引不更新');
                }

                $ids = $orders->pluck('shipment.id')->filter()->map(fn ($id): int => (int) $id)->all();
                $tracking = $tracker->forShipments($ids);
                foreach ($orders as $order) {
                    $candidates++;
                    $record = $tracking[(int) $order->shipment?->id] ?? null;

                    // A configured Woo connector must be readable. If an
                    // external inspection failed, fail the entire snapshot
                    // rather than mislabel the affected shipment as unsynced.
                    if ($remoteConfigured && ($record['remote_read_error'] ?? false)) {
                        throw new RuntimeException('WooCommerce 状态回读异常，无法完成全库同步状态筛选');
                    }

                    $status = $tracker->currentStatus($record, $order->manualSync?->website_sync_status);
                    $root = $order->fulfillment_parent_id ? $order->fulfillmentParent : $order;
                    $type = $root?->source_type ?? $order->source_type;
                    $type = $type instanceof \BackedEnum ? $type->value : (string) $type;
                    $progress = $projector->forOrder($order, $status, $type === 'woocommerce');
                    if ($progress['code'] !== 'unsynced') {
                        $nonUnsynced[(int) $order->id] = $progress['code'];
                    }
                }
            }, 'orders.id', 'id');

        return [
            'generated_at' => now()->toIso8601String(),
            'total' => $total,
            'candidates' => $candidates,
            'remote_configured' => $remoteConfigured,
            'non_unsynced' => $nonUnsynced,
        ];
    }

    /**
     * Apply a full-order status predicate to an existing base Order query,
     * before pagination and ORDER BY. No current-page-only AG Grid filtering.
     *
     * @param  array{non_unsynced:array<int,string>}  $snapshot
     */
    public function constrain(Builder $query, string $code, array $snapshot): void
    {
        $nonDefault = $snapshot['non_unsynced'];
        if ($code === 'unsynced') {
            $query->whereNotIn('orders.id', array_keys($nonDefault));

            return;
        }

        $ids = [];
        foreach ($nonDefault as $id => $status) {
            if ($status === $code) {
                $ids[] = (int) $id;
            }
        }

        $query->whereIn('orders.id', $ids ?: [-1]);
    }

    public function codeFor(int $id, array $snapshot): string
    {
        return $snapshot['non_unsynced'][$id] ?? 'unsynced';
    }
}
