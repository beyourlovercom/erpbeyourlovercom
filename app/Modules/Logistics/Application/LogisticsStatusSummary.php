<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Logistics\Infrastructure\Models\LogisticsReview;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\Eloquent\Builder;

final class LogisticsStatusSummary
{
    public function pendingAllocationQuery(): Builder
    {
        // Retain the latest Processing, KOL and operational-archive gates.
        return app(\App\Modules\Orders\Application\OrderProgressQuery::class)
            ->pendingAllocation(
                app(PendingAllocationEntryPolicy::class)
                    ->constrain(Order::query()->eligibleForOperations())
            )
            ->whereDoesntHave('externalReferences', fn ($q) => $q->where('provider', 'historical_logistics'));

    }

    public function waitStockCount(): int
    {
        return $this->pendingAllocationQuery()->count();
    }

    public function statusBar(): array
    {
        $review = LogisticsReview::query()
            ->where('status', 'pending')
            ->whereHas('order', fn ($q) => $q->eligibleForOperations()->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))
            ->count();

        $active = fn ($q) => $q
            ->whereDoesntHave('order.logisticsReview', fn ($r) => $r->where('status', 'pending'))
            ->whereHas('order', fn ($o) => $o->eligibleForOperations()
                ->where('order_status', '!=', 'cancelled')
                ->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')));

        return [
            ['key' => 'awaiting', 'label' => '已发货', 'url' => '/logistics/awaiting-tracking', 'count' => Shipment::query()->where('shipment_status', 'shipped')->whereNotNull('shipped_at')->where(fn ($q) => $q->whereNull('tracking_number')->orWhere('tracking_number', ''))->where($active)->count()],
            ['key' => 'transit', 'label' => '运输中', 'url' => '/logistics/in-transit', 'count' => Shipment::query()->where('shipment_status', 'shipped')->whereNotNull('tracking_number')->where('tracking_number', '!=', '')->where($active)->count()],
            ['key' => 'exception', 'label' => '异常单', 'url' => '/logistics/exceptions', 'count' => Shipment::query()->where('shipment_status', 'exception')->where($active)->count()],
            ['key' => 'review', 'label' => '复核单', 'url' => '/logistics/review', 'count' => $review],
            ['key' => 'completed', 'label' => '已完结', 'url' => '/logistics/completed', 'count' => Shipment::query()->where('shipment_status', 'completed')->where($active)->count()],
            ['key' => 'cancelled', 'label' => '已取消', 'url' => '/logistics/cancelled', 'count' => Order::query()->eligibleForOperations()->where('order_status', 'cancelled')->whereDoesntHave('externalReferences', fn ($q) => $q->where('provider', 'historical_logistics'))->count()],
        ];
    }
}
