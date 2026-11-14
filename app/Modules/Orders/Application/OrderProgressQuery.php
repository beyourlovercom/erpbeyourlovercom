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
        return $this->withoutManualException($orders)
            ->whereHas('shipment', fn ($q) => $q->where('shipment_status', 'pending'))
            ->whereDoesntHave('operationalMarkers', fn ($q) => $q->whereIn('marker', ['domestic_reviewed', 'domestic_stockout']))
            ->where(fn ($q) => $q->whereNull('workflow_status')->orWhere('workflow_status', '!=', 'out_of_stock'));
    }

    public function domesticStockout(Builder $orders): Builder
    {
        return $this->withoutManualException($orders)
            ->whereHas('shipment', fn ($q) => $q->where('shipment_status', 'pending'))
            ->where(fn ($q) => $q->where('workflow_status', 'out_of_stock')
                ->orWhereHas('operationalMarkers', fn ($m) => $m->where('marker', 'domestic_stockout')));
    }

    public function domesticReady(Builder $orders): Builder
    {
        return $this->withoutManualException($orders)
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
        return $this->withoutManualException($orders)->where('order_status', 'pending_shipment')
            ->where(fn ($q) => $q->whereNull('workflow_status')->orWhere('workflow_status', '!=', 'out_of_stock'))
            ->whereDoesntHave('operationalMarkers', fn ($m) => $m->whereIn('marker', ['stockout', 'domestic_stockout']))
            ->whereNull('fulfillment_parent_id')
            ->where(function (Builder $allocationGate): void {
                // A completed submission usually stays out of Vita's queue.
                // Exception: Dede explicitly rejected its *current* overseas
                // review and unassigned the warehouse. Older rejected orders
                // retain status=submitted, so they must be read as actionable
                // without rewriting historical allocation/audit records.
                $allocationGate->whereDoesntHave('fulfillmentAllocation', fn ($a) => $a->where('status', 'submitted'))
                    ->orWhere(function (Builder $returned): void {
                        $returned->whereHas('overseaWarehouseReview', fn ($review) => $review->where('status', 'rejected'))
                            ->whereHas('shipment', fn ($shipment) => $shipment
                                ->where('shipment_status', 'pending')
                                ->whereNull('warehouse_id')
                                ->whereNull('shipped_at')
                                ->where(fn ($tracking) => $tracking->whereNull('tracking_number')
                                    ->orWhere('tracking_number', '')));
                    });
            })
            ->where(function (Builder $pending): void {
                $pending->whereDoesntHave('shipment')
                    ->orWhereHas('shipment', fn ($s) => $s->whereNull('warehouse_id'));
            });
    }

    /** Shared operational Sending queue, for customer order listing and segments. */
    public function readyForShipment(Builder $orders): Builder
    {
        return $this->withoutManualException($orders)->where('order_status', 'pending_shipment')
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
        if (! in_array($phase, ['exception', 'cancelled', 'returned_follow_up'], true)) {
            $this->withoutManualException($orders);
            $orders->where('order_status', '!=', 'cancelled');
        }

        return match ($phase) {
            'exception' => $orders->where('order_status', '!=', 'cancelled')->where(function (Builder $q): void {
                $q->whereIn('workflow_status', OrderManualHoldStatus::EXCEPTION_CODES)
                    ->orWhere(function (Builder $legacy): void {
                        $legacy->where(fn ($w) => $w->whereNull('workflow_status')
                            ->orWhereNotIn('workflow_status', OrderManualHoldStatus::CODES))
                            ->whereHas('operationalMarkers', fn ($m) => $m
                                ->whereIn('marker', OrderManualHoldStatus::EXCEPTION_MARKERS));
                    })
                    ->orWhereHas('shipment', fn ($s) => $s->where('shipment_status', 'exception'))
                    ->orWhereHas('fulfillmentParent', fn ($parent) => $parent
                        ->whereIn('workflow_status', OrderManualHoldStatus::EXCEPTION_CODES)
                        ->orWhereHas('operationalMarkers', fn ($m) => $m
                            ->whereIn('marker', OrderManualHoldStatus::EXCEPTION_MARKERS)))
                    ->orWhereHas('originalFulfillmentChildren', fn ($child) => $child
                        ->whereIn('workflow_status', OrderManualHoldStatus::EXCEPTION_CODES)
                        ->orWhereHas('operationalMarkers', fn ($m) => $m
                            ->whereIn('marker', OrderManualHoldStatus::EXCEPTION_MARKERS))
                        ->orWhereHas('shipment', fn ($s) => $s->where('shipment_status', 'exception')));
            }),
            // Return follow-ups are a real primary state, not generic exceptions.
            'returned_follow_up' => $orders->where('order_status', '!=', 'cancelled')
                ->whereDoesntHave('shipment', fn ($s) => $s->where('shipment_status', 'exception'))
                ->where(fn (Builder $q) => $q->where('workflow_status', 'returned_follow_up')
                    ->orWhere(function (Builder $legacy): void {
                        $legacy->where(fn ($w) => $w->whereNull('workflow_status')
                            ->orWhereNotIn('workflow_status', OrderManualHoldStatus::CODES))
                            ->whereHas('operationalMarkers', fn ($m) => $m->where('marker', 'workflow_aux_returned_follow_up'))
                            ->whereDoesntHave('operationalMarkers', fn ($m) => $m->whereIn('marker', OrderManualHoldStatus::EXCEPTION_MARKERS));
                    })),
            'cancelled' => $orders->where('order_status', 'cancelled'),
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
                    ->orWhereHas('operationalMarkers', fn ($m) => $m->whereIn('marker', ['domestic_stockout', 'stockout']))
                    ->orWhereHas('shipment', fn ($s) => $s->whereHas('overseaReview', fn ($r) => $r
                        ->where('status', 'stockout')->whereColumn('reviewed_warehouse_id', 'shipments.warehouse_id'))))
                ->where(fn ($q) => $q->whereDoesntHave('shipment')
                    ->orWhereHas('shipment', fn ($s) => $s->where('shipment_status', 'pending'))),
            'allocated' => $orders->where('order_status', 'pending_shipment')
                ->whereNull('fulfillment_parent_id')
                ->whereHas('fulfillmentAllocation', fn ($a) => $a->where('status', 'submitted'))
                ->whereDoesntHave('shipment', fn ($s) => $s->where('shipment_status', '!=', 'pending'))
                ->whereDoesntHave('originalFulfillmentChildren', fn ($c) => $c
                    ->whereHas('shipment', fn ($s) => $s->whereIn('shipment_status', ['shipped', 'completed']))),
            'partially_shipped' => $orders->whereNull('fulfillment_parent_id')
                ->whereHas('originalFulfillmentChildren', fn ($c) => $c
                    ->whereHas('shipment', fn ($s) => $s->whereIn('shipment_status', ['shipped', 'completed'])))
                ->whereHas('originalFulfillmentChildren', fn ($c) => $c
                    ->whereDoesntHave('shipment')->orWhereHas('shipment', fn ($s) => $s->where('shipment_status', 'pending')))
                ->whereDoesntHave('originalFulfillmentChildren', fn (Builder $c) => $this->directConflict($c)),
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
            'progress_conflict' => $orders->where(function (Builder $q): void {
                $this->directConflict($q)
                    ->orWhereHas('originalFulfillmentChildren', fn (Builder $child) => $this->directConflict($child));
            }),
            'shipping' => $orders->whereHas('shipment', fn ($s) => $s
                ->where('shipment_status', 'shipped')->whereNotNull('tracking_number')->where('tracking_number', '!=', '')),
            'completed' => $orders->whereIn('order_status', ['completed', 'completed_delivered']),
            default => $orders->whereRaw('1=0'),
        };
    }

    /** Suppress every manual exception override from normal primary queues. */
    private function withoutManualException(Builder $orders): Builder
    {
        return $orders
            ->where(fn (Builder $q) => $q->whereNull('workflow_status')
                ->orWhereNotIn('workflow_status', OrderManualHoldStatus::CODES))
            ->whereDoesntHave('operationalMarkers', fn ($m) => $m
                ->whereIn('marker', OrderManualHoldStatus::HOLD_MARKERS))
            ->whereDoesntHave('shipment', fn ($s) => $s->where('shipment_status', 'exception'))
            ->whereDoesntHave('fulfillmentParent', fn ($p) => $p
                ->whereIn('workflow_status', OrderManualHoldStatus::CODES)
                ->orWhereHas('operationalMarkers', fn ($m) => $m
                    ->whereIn('marker', OrderManualHoldStatus::HOLD_MARKERS)))
            ->whereDoesntHave('originalFulfillmentChildren', fn ($c) => $c
                ->whereIn('workflow_status', OrderManualHoldStatus::CODES)
                ->orWhereHas('operationalMarkers', fn ($m) => $m
                    ->whereIn('marker', OrderManualHoldStatus::HOLD_MARKERS))
                ->orWhereHas('shipment', fn ($s) => $s->where('shipment_status', 'exception')));
    }

    /** Reusable direct-segment reconciliation predicate, excluding root roll-up. */
    private function directConflict(Builder $orders): Builder
    {
        return $orders->where(function (Builder $bad): void {
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
                    $stock->where(fn (Builder $q) => $q->where('workflow_status', 'out_of_stock')
                        ->orWhereHas('operationalMarkers', fn ($m) => $m->whereIn('marker', ['stockout', 'domestic_stockout'])))
                        ->whereHas('shipment', fn ($s) => $s->where('shipment_status', 'shipped'));
                });
        });
    }

    /** $shipments is a Shipment model query. */
    public function overseasReview(Builder $shipments): Builder
    {
        return $shipments->whereHas('order', fn (Builder $order) => $this->withoutManualException($order))
            ->where('shipment_status', 'pending')->where(function ($q): void {
            $q->whereDoesntHave('overseaReview')
                ->orWhereHas('overseaReview', fn ($r) => $r->whereNotIn('status', ['approved', 'stockout']))
                ->orWhereHas('overseaReview', fn ($r) => $r
                    ->whereIn('status', ['approved', 'stockout'])
                    ->where(function ($mismatch): void {
                        $mismatch->whereNull('reviewed_warehouse_id')
                            ->orWhereColumn('reviewed_warehouse_id', '!=', 'shipments.warehouse_id');
                    }));
        });
    }

    /** Stockout remains allocated, is excluded from the review queue and cannot ship. */
    public function overseasStockout(Builder $shipments): Builder
    {
        return $shipments->whereHas('order', fn (Builder $order) => $this->withoutManualException($order))
            ->where('shipment_status', 'pending')
            ->whereHas('overseaReview', fn ($review) => $review
                ->where('status', 'stockout')
                ->whereColumn('reviewed_warehouse_id', 'shipments.warehouse_id'));
    }

    /** $shipments is a Shipment model query. */
    public function overseasReady(Builder $shipments): Builder
    {
        return $shipments->whereHas('order', fn (Builder $order) => $this->withoutManualException($order))
            ->where('shipment_status', 'pending')
            ->whereHas('overseaReview', fn ($r) => $r
                ->where('status', 'approved')
                ->whereColumn('reviewed_warehouse_id', 'shipments.warehouse_id'));
    }
}
