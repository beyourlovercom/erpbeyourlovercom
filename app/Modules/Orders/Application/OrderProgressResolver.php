<?php

namespace App\Modules\Orders\Application;

use App\Modules\Logistics\Application\OverseaWarehouseReviewService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Collection;

/**
 * One read-only operational phase derived from authoritative facts.
 * Does not change order, shipment, allocation, inventory, or review data.
 */
final class OrderProgressResolver
{
    public function resolve(Order $order): ?string
    {
        if (! in_array($order->order_kind, [OrderKind::Customer, OrderKind::FulfillmentDerived], true)) {
            return null; // KOL business phases keep their pre-V3 semantics.
        }

        if ($order->archived_at !== null || $order->isOperationallyArchived()) {
            return 'archived';
        }
        if ($order->order_status === OrderStatus::Cancelled) {
            return 'cancelled';
        }

        // A deliberately escalated exception is an actionable core queue, not just a badge.
        // Real Shipment and source Woo facts remain unchanged underneath the override.
        $order->loadMissing('operationalMarkers');
        if ($order->workflow_status === 'exception'
            || $order->operationalMarkers->contains('marker', 'exception')) {
            return 'exception';
        }
        if ($order->fulfillment_parent_id !== null) {
            $order->loadMissing('fulfillmentParent.operationalMarkers');
            $parent = $order->fulfillmentParent;
            if ($parent && ($parent->workflow_status === 'exception'
                || $parent->operationalMarkers->contains('marker', 'exception'))) {
                return 'exception';
            }
        }

        if ($order->fulfillment_parent_id === null) {
            $order->loadMissing('originalFulfillmentChildren');
            if ($order->originalFulfillmentChildren->isNotEmpty()) {
                return $this->aggregateChildren($order->originalFulfillmentChildren);
            }
        }

        if (in_array($order->order_status, [OrderStatus::Completed, OrderStatus::CompletedDelivered], true)) {
            return 'completed';
        }

        // Parent is a non-executable aggregate after Vita commits allocation.
        if ($order->fulfillment_parent_id === null) {
            $order->loadMissing('fulfillmentAllocation');
        }
        if ($order->fulfillment_parent_id === null && $order->fulfillmentAllocation?->status === 'submitted') {
            // A legacy submitted allocation must not mask authoritative shipment facts.
            // In particular, an already-shipped source order without evidence must
            // remain a reconciliation exception instead of appearing as allocated.
            $order->loadMissing('shipment');
            if ($order->shipment === null || $order->shipment->shipment_status === 'pending') {
                return 'allocated';
            }
        }

        $order->loadMissing(['shipment.warehouse', 'shipment.overseaReview', 'operationalMarkers']);
        $shipment = $order->shipment;
        $notes = $order->operationalMarkers->pluck('marker');
        // Legacy generic stockout markers remain meaningful until explicitly cleared.
        $stockout = $order->workflow_status === 'out_of_stock'
            || $notes->contains('domestic_stockout') || $notes->contains('stockout');

        if ($shipment === null || $shipment->warehouse_id === null) {
            return $order->order_status === OrderStatus::Shipped ? 'progress_conflict'
                : ($stockout ? 'out_of_stock' : 'pending_allocation');
        }

        $factory = strtoupper(trim((string) $shipment->warehouse?->code)) === 'FACTORY';
        $overseas = OverseaWarehouseReviewService::canonicalWarehouseCode($shipment->warehouse?->code) !== null;
        $domesticReviewed = $notes->contains('domestic_reviewed');

        if (in_array($shipment->shipment_status, ['shipped', 'completed'], true)) {
            $review = $overseas ? $shipment->overseaReview : null;
            $overseasReviewed = $review && $review->status === 'approved'
                && (int) $review->reviewed_warehouse_id === (int) $shipment->warehouse_id;
            if ($stockout || $shipment->shipped_at === null
                || (($factory && ! $domesticReviewed) || ($overseas && ! $overseasReviewed))
                    && blank($shipment->tracking_number)) {
                return 'progress_conflict';
            }

            return $shipment->shipment_status === 'completed'
                ? 'completed'
                : (filled($shipment->tracking_number) ? 'shipping' : 'sent');
        }

        if ($shipment->shipment_status === 'exception') {
            return 'exception';
        }

        if ($shipment->shipment_status !== 'pending' || $order->order_status === OrderStatus::Shipped) {
            return 'progress_conflict';
        }

        if ($stockout) {
            return 'out_of_stock';
        }

        if ($factory) {
            return $domesticReviewed ? 'sending' : 'pending_review';
        }

        if ($overseas) {
            $approval = $shipment->overseaReview;
            if ($approval?->status === 'stockout'
                && (int) $approval->reviewed_warehouse_id === (int) $shipment->warehouse_id) {
                return 'out_of_stock';
            }

            return $approval && $approval->status === 'approved'
                && (int) $approval->reviewed_warehouse_id === (int) $shipment->warehouse_id
                ? 'sending' : 'pending_review';
        }

        return 'pending_review';
    }

    /** Non-executable source order follows a read-only rollup of its own fulfillment segments. */
    private function aggregateChildren(Collection $children): string
    {
        $phases = $children->map(fn (Order $child): ?string => $this->resolve($child))->values();

        if ($phases->contains('exception')) {
            return 'exception';
        }
        if ($phases->contains('progress_conflict')) {
            return 'progress_conflict';
        }
        if ($phases->every(fn ($phase) => $phase === 'completed')) {
            return 'completed';
        }

        $active = ['sent', 'shipping', 'completed'];
        $moved = $phases->filter(fn ($phase) => in_array($phase, $active, true))->count();
        if ($moved > 0 && $moved < $phases->count()) {
            return 'partially_shipped';
        }
        if ($moved === $phases->count()) {
            return $phases->contains('sent') ? 'sent' : 'shipping';
        }
        if ($phases->every(fn ($phase) => $phase === 'out_of_stock')) {
            return 'out_of_stock';
        }

        return 'allocated';
    }
}
