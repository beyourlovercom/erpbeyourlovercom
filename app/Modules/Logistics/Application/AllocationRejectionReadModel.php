<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only projection for the Vita wait-stock queue. The original approval,
 * payment and pending-allocation gates must always be applied before this scope.
 */
final class AllocationRejectionReadModel
{
    public function onlyRejected(Builder $orders): Builder
    {
        return $orders->where(function (Builder $query): void {
            $query->where(function (Builder $domestic): void {
                $domestic->whereNotNull('domestic_reject_reason')
                    ->where('domestic_reject_reason', '!=', '');
            })->orWhereHas('overseaWarehouseReview', fn (Builder $review) => $review->where('status', 'rejected'));
        });
    }

    /** @return array{count:int,notice_version:string} */
    public function snapshot(Builder $orders): array
    {
        // Single server-backed aggregate for cross-page count AND alert identity.
        // A new rejection still retriggers the notice when the total remains the
        // same because another order was reallocated in between.
        $row = $this->onlyRejected($orders)
            ->leftJoin('oversea_warehouse_reviews as rejection_review',
                'rejection_review.order_id', '=', 'orders.id')
            ->selectRaw('COUNT(DISTINCT orders.id) as rejected_count, SUM(orders.id) as id_checksum')
            ->selectRaw('MAX(CASE
                WHEN orders.domestic_rejected_at IS NULL THEN rejection_review.reviewed_at
                WHEN rejection_review.reviewed_at IS NULL THEN orders.domestic_rejected_at
                WHEN orders.domestic_rejected_at >= rejection_review.reviewed_at THEN orders.domestic_rejected_at
                ELSE rejection_review.reviewed_at END) as last_rejected_at')
            ->first();

        $count = (int) ($row?->rejected_count ?? 0);
        return [
            'count' => $count,
            'notice_version' => hash('sha256',
                $count.'|'.($row?->last_rejected_at ?? '').'|'.($row?->id_checksum ?? '')),
        ];
    }

    /** @return array{source:string,reason:string,by:string|null,at:string|null}|null */
    public function forOrder(Order $order): ?array
    {
        if (filled($order->domestic_reject_reason)) {
            return [
                'source' => '国内商务',
                'reason' => trim((string) $order->domestic_reject_reason),
                'by' => $order->domesticRejecter?->name,
                'at' => $order->domestic_rejected_at?->toIso8601String(),
            ];
        }

        $review = $order->overseaWarehouseReview;
        if ($review?->status !== 'rejected') {
            return null;
        }

        return [
            'source' => '海外仓',
            'reason' => trim((string) $review->note) ?: '历史驳回未记录原因',
            'by' => $review->reviewer?->name,
            'at' => $review->reviewed_at?->toIso8601String(),
        ];
    }
}
