<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Orders\Application\OrderProgressQuery;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\Eloquent\Builder;

final class DomesticPendingQueue
{
    public function __construct(private readonly OrderProgressQuery $progress) {}

    public function base(int $factoryId): Builder
    {
        return Order::query()->eligibleForOperations()->where('order_status', '!=', 'cancelled')
            ->whereDoesntHave('fulfillmentChildren')
            ->whereDoesntHave('externalReferences', fn ($q) => $q->where('provider', 'historical_logistics'))
            ->where(function (Builder $routes) use ($factoryId): void {
                $routes->whereIn('routing_warehouse_code', ['FACTORY', 'OUTSOURCE'])
                    ->orWhere(fn (Builder $fallback) => $fallback->whereNull('routing_warehouse_code')
                        ->whereHas('shipment', fn ($shipment) => $shipment->where('warehouse_id', $factoryId)));
            });
    }

    public function pending(Builder $orders): Builder
    {
        return $this->progress->domesticReady($orders);
    }
}
