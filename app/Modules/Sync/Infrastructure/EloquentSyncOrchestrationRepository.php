<?php

namespace App\Modules\Sync\Infrastructure;

use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\Contracts\SyncOrchestrationRepository;
use App\Modules\Sync\Application\SyncOutboxStatus;
use App\Modules\Sync\Infrastructure\Models\SyncOutbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class EloquentSyncOrchestrationRepository implements SyncOrchestrationRepository
{
    public function ordersByIds(array $ids): Collection
    {
        return Order::query()
            ->with(['shipment', 'externalReferences', 'manualSync'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    public function eligibleTrackingOrders(int $limit, ?int $minShipmentId = null): Collection
    {
        return Order::query()
            ->with(['shipment', 'externalReferences', 'manualSync'])
            ->where(function (Builder $query): void {
                $query->where('source_type', 'woocommerce')
                    ->orWhereHas('fulfillmentParent', fn (Builder $parent) => $parent->where('source_type', 'woocommerce'));
            })
            ->whereHas('shipment', function (Builder $query) use ($minShipmentId): void {
                if ($minShipmentId !== null) {
                    $query->where('id', '>', $minShipmentId);
                    // Exclude active or terminal failed intents from new-discovery.
                    // Otherwise an Outbox retried earlier in this same tick could be
                    // sent a second time through the new-Shipment branch.
                    $query->whereNotExists(function (\Illuminate\Database\Query\Builder $sub): void {
                        $sub->selectRaw('1')
                            ->from('sync_outbox')
                            ->where('sync_outbox.aggregate_type', 'shipment')
                            ->where('sync_outbox.operation', 'woocommerce.tracking_outbound')
                            ->whereColumn('sync_outbox.aggregate_id', 'shipments.id')
                            ->whereIn('sync_outbox.status', ['pending', 'processing', 'failed']);
                    });
                }
                $query->where('shipment_status', 'shipped')
                    ->whereNotNull('tracking_number')->where('tracking_number', '<>', '')
                    ->whereNotNull('carrier')->where('carrier', '<>', '')
                    ->whereNotNull('shipped_at');
            })
            ->whereHas('manualSync', fn (Builder $query) => $query->where('website_sync_status', 'pending'))
            ->orderBy('orders.id')
            ->limit($limit)
            ->get();
    }

    public function dueTrackingOutbox(int $limit, ?int $minOutboxId = null): Collection
    {
        return SyncOutbox::query()
            ->where('operation', 'woocommerce.tracking_outbound')
            ->when($minOutboxId !== null, fn ($q) => $q->where('id', '>', $minOutboxId))
            ->where('status', SyncOutboxStatus::Pending)
            ->where('available_at', '<=', now())
            ->whereColumn('attempts', '<', 'max_attempts')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function shipmentWithOrder(int $shipmentId): ?Model
    {
        return Shipment::query()
            ->with(['order.externalReferences', 'order.manualSync'])
            ->find($shipmentId);
    }
    public function queueSummary(string $operation): array
    {
        $base = SyncOutbox::query()->where('operation', $operation);

        return [
            'retry_due' => (clone $base)->where('status', SyncOutboxStatus::Pending)
                ->where('available_at', '<=', now())->whereColumn('attempts', '<', 'max_attempts')->count(),
            'retry_waiting' => (clone $base)->where('status', SyncOutboxStatus::Pending)
                ->where('available_at', '>', now())->count(),
            'processing' => (clone $base)->where('status', SyncOutboxStatus::Processing)->count(),
            'failed' => (clone $base)->where('status', SyncOutboxStatus::Failed)->count(),
            'conflicts' => (clone $base)->where('status', SyncOutboxStatus::Failed)
                ->where('last_error_code', 'remote_tracking_conflict')->count(),
        ];
    }
}
