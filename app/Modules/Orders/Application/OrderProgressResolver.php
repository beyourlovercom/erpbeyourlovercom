<?php

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Logistics\Application\OverseaWarehouseReviewService;

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

        if ($order->order_status === OrderStatus::Cancelled) {
            return 'cancelled';
        }

        if (in_array($order->order_status, [OrderStatus::Completed, OrderStatus::CompletedDelivered], true)) {
            return 'completed';
        }

        // Parent is a non-executable aggregate after Vita commits allocation.
        $order->loadMissing('fulfillmentAllocation');
        if ($order->fulfillment_parent_id === null && $order->fulfillmentAllocation?->status === 'submitted') {
            return 'allocated';
        }

        $order->loadMissing(['shipment.warehouse', 'shipment.overseaReview', 'operationalMarkers']);
        $shipment = $order->shipment;
        $notes = $order->operationalMarkers->pluck('marker');
        $stockout = $order->workflow_status === 'out_of_stock' || $notes->contains('domestic_stockout');

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
            return 'renew';
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
            return $approval && $approval->status === 'approved'
                && (int) $approval->reviewed_warehouse_id === (int) $shipment->warehouse_id
                ? 'sending' : 'pending_review';
        }

        return 'pending_review';
    }
}
