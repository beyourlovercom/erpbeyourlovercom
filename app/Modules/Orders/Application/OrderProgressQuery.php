<?php

namespace App\Modules\Orders\Application;

use Illuminate\Database\Eloquent\Builder;

/**
 * R0-R3: shared, read-only operational progress predicates.
 *
 * The supplied Builder is an Order query except overseas* methods,
 * which expect a Shipment query. No mutation or backfill is performed.
 */
final class OrderProgressQuery
{
    public function domesticReview(Builder $orders): Builder
    {
        return $orders
            ->whereHas('shipment', fn ($q) => $q->where('shipment_status', 'pending'))
            ->whereDoesntHave('operationalMarkers', fn ($q) => $q->whereIn('marker', ['domestic_reviewed', 'domestic_stockout']))
            ->where(fn ($q) => $q->whereNull('workflow_status')->orWhere('workflow_status', '!=', 'out_of_stock'));
    }

    public function domesticStockout(Builder $orders): Builder
    {
        return $orders
            ->whereHas('shipment', fn ($q) => $q->where('shipment_status', 'pending'))
            ->where(fn ($q) => $q->where('workflow_status', 'out_of_stock')
                ->orWhereHas('operationalMarkers', fn ($m) => $m->where('marker', 'domestic_stockout')));
    }

    public function domesticReady(Builder $orders): Builder
    {
        return $orders
            ->whereHas('shipment', fn ($q) => $q->where('shipment_status', 'pending'))
            ->whereHas('operationalMarkers', fn ($q) => $q->where('marker', 'domestic_reviewed'))
            ->whereDoesntHave('operationalMarkers', fn ($q) => $q->where('marker', 'domestic_stockout'))
            ->where(fn ($q) => $q->whereNull('workflow_status')->orWhere('workflow_status', '!=', 'out_of_stock'));
    }

    public function domesticTodayProvider(Builder $orders, string $carrier): Builder
    {
        $carrier = strtolower(trim($carrier));

        return $orders->where(function (Builder $daily) use ($carrier): void {
            $this->domesticReady($daily->whereHas('shipment', fn ($q) => $q->whereRaw('LOWER(carrier) = ?', [$carrier])))
                ->orWhereHas('shipment', fn ($q) => $q->whereDate('shipped_at', now()->toDateString())
                    ->whereRaw('LOWER(carrier) = ?', [$carrier]));
        });
    }

    public function domesticToday(Builder $orders): Builder
    {
        return $orders->where(function (Builder $daily): void {
            $this->domesticReady($daily)
                ->orWhereHas('shipment', fn ($q) => $q->whereDate('shipped_at', now()->toDateString()));
        });
    }

    /** Vita queue; never count a submitted split-allocation root. */
    public function pendingAllocation(Builder $orders): Builder
    {
        return $orders->where('order_status', 'pending_shipment')
            ->whereNull('fulfillment_parent_id')
            ->whereDoesntHave('fulfillmentAllocation', fn ($a) => $a->where('status', 'submitted'))
            ->where(function (Builder $pending): void {
                $pending->whereDoesntHave('shipment')
                    ->orWhereHas('shipment', fn ($s) => $s->whereNull('warehouse_id'));
            });
    }

    /** Shared operational Sending queue, for customer order listing and segments. */
    public function readyForShipment(Builder $orders): Builder
    {
        return $orders->where('order_status', 'pending_shipment')
            ->where(fn ($q) => $q->whereNull('workflow_status')->orWhere('workflow_status', '!=', 'out_of_stock'))
            ->whereDoesntHave('operationalMarkers', fn ($m) => $m->where('marker', 'domestic_stockout'))
            ->where(function (Builder $channels): void {
                $channels->where(function (Builder $factory): void {
                    $factory->whereHas('shipment.warehouse', fn ($w) => $w->whereRaw('UPPER(code) = ?', ['FACTORY']));
                    $this->domesticReady($factory);
                })->orWhereHas('shipment', fn ($shipment) => $this->overseasReady($shipment)
                    ->whereHas('warehouse', fn ($warehouse) => $warehouse->whereIn('code', ['US-NY', 'US-LA', 'US-SM', 'DE', 'CA'])));
            });
    }

    /** Exact predicates used by operational progress filtering. */
    public function applyPhase(Builder $orders, string $phase): Builder
    {
        return match ($phase) {
            'pending_allocation' => $this->pendingAllocation($orders),
            'sending' => $this->readyForShipment($orders),
            'pending_review' => $orders->where('order_status', 'pending_shipment')
                ->whereHas('shipment', fn ($s) => $s->where('shipment_status', 'pending')->whereNotNull('warehouse_id'))
                ->whereDoesntHave('operationalMarkers', fn ($m) => $m->where('marker', 'domestic_stockout'))
                ->where(fn ($q) => $q->whereNull('workflow_status')->orWhere('workflow_status', '!=', 'out_of_stock'))
                ->where(function (Builder $review): void {
                    $review->where(function (Builder $factory): void {
                        $factory->whereHas('shipment.warehouse', fn ($w) => $w->where('code', 'FACTORY'));
                        $this->domesticReview($factory);
                    })->orWhereHas('shipment', fn ($s) => $this->overseasReview($s)
                        ->whereHas('warehouse', fn ($w) => $w->whereIn('code', ['US-NY', 'US-LA', 'US-SM', 'DE', 'CA'])));
                }),
            'out_of_stock' => $orders
                ->where(fn ($q) => $q->where('workflow_status', 'out_of_stock')
                    ->orWhereHas('operationalMarkers', fn ($m) => $m->whereIn('marker', ['domestic_stockout', 'stockout'])))
                ->where(fn ($q) => $q->whereDoesntHave('shipment')
                    ->orWhereHas('shipment', fn ($s) => $s->where('shipment_status', 'pending'))),
            'allocated' => $orders->where('order_status', 'pending_shipment')
                ->whereNull('fulfillment_parent_id')
                ->whereHas('fulfillmentAllocation', fn ($a) => $a->where('status', 'submitted')),
            'sent' => $orders->whereHas('shipment', fn ($s) => $s
                ->where('shipment_status', 'shipped')->whereNotNull('shipped_at')
                ->where(fn ($q) => $q->whereNull('tracking_number')->orWhere('tracking_number', '')))
                ->where(fn (Builder $approval) => $approval
                    ->where(function (Builder $factory): void {
                        $factory->whereHas('shipment.warehouse', fn ($w) => $w->where('code', 'FACTORY'))
                            ->whereHas('operationalMarkers', fn ($m) => $m->where('marker', 'domestic_reviewed'));
                    })->orWhereHas('shipment', fn ($shipment) => $shipment
                        ->whereHas('overseaReview', fn ($r) => $r->where('status', 'approved')
                            ->whereColumn('reviewed_warehouse_id', 'shipments.warehouse_id')))),
            'progress_conflict' => $orders->where(function (Builder $bad): void {
                $bad->whereHas('shipment', fn ($s) => $s->where('shipment_status', 'shipped')
                    ->whereNull('shipped_at'))
                    ->orWhere(function (Builder $factory): void {
                        $factory->whereHas('shipment', fn ($s) => $s
                            ->where('shipment_status', 'shipped')->whereHas('warehouse', fn ($w) => $w->where('code', 'FACTORY'))
                            ->where(fn ($t) => $t->whereNull('tracking_number')->orWhere('tracking_number', '')))
                            ->whereDoesntHave('operationalMarkers', fn ($m) => $m->where('marker', 'domestic_reviewed'));
                    })
                    ->orWhere(function (Builder $stale): void {
                        $stale->where('order_status', 'shipped')->whereHas('shipment', fn ($s) => $s->where('shipment_status', 'pending'));
                    })
                    ->orWhere(function (Builder $stock): void {
                        $stock->where('workflow_status', 'out_of_stock')->whereHas('shipment', fn ($s) => $s->where('shipment_status', 'shipped'));
                    });
            }),
            'shipping' => $orders->whereHas('shipment', fn ($s) => $s
                ->where('shipment_status', 'shipped')->whereNotNull('tracking_number')->where('tracking_number', '!=', '')),
            'completed' => $orders->whereIn('order_status', ['completed', 'completed_delivered']),
            default => $orders->whereRaw('1=0'),
        };
    }

    /** $shipments is a Shipment model query. */
    public function overseasReview(Builder $shipments): Builder
    {
        return $shipments->where('shipment_status', 'pending')->where(function ($q): void {
            $q->whereDoesntHave('overseaReview')
                ->orWhereHas('overseaReview', fn ($r) => $r->where('status', '!=', 'approved'))
                ->orWhereHas('overseaReview', fn ($r) => $r
                    ->where('status', 'approved')
                    ->whereColumn('reviewed_warehouse_id', '!=', 'shipments.warehouse_id'));
        });
    }

    /** $shipments is a Shipment model query. */
    public function overseasReady(Builder $shipments): Builder
    {
        return $shipments->where('shipment_status', 'pending')
            ->whereHas('overseaReview', fn ($r) => $r
                ->where('status', 'approved')
                ->whereColumn('reviewed_warehouse_id', 'shipments.warehouse_id'));
    }
}
