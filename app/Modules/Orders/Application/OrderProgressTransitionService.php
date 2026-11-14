<?php

namespace App\Modules\Orders\Application;

use App\Modules\Logistics\Application\Domestic\DomesticReviewDecisionService;
use App\Modules\Logistics\Application\LogisticsReviewAccess;
use App\Modules\Logistics\Application\OverseaWarehouseReviewService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The existing order-status dropdown delegates real fulfillment actions to
 * their authority-owning services. It may never create shipment evidence.
 */
final class OrderProgressTransitionService
{
    public function __construct(
        private readonly OrderWorkflowStatusService $annotations,
        private readonly DomesticReviewDecisionService $domestic,
        private readonly OrderAuxiliaryStatusService $auxiliary,
        private readonly OrderCoreStatusTransitionService $core,
        private readonly OverseaWarehouseReviewService $oversea,
        private readonly LogisticsReviewAccess $reviewAccess,
    ) {}

    public function transition(Order $order, string $next, int $revision, Authenticatable $actor, ?string $reason = null): Order
    {
        if (! in_array($next, ['resolve_exception', 'resolve_stockout', 'resolve_manual_hold'], true)) {
            OrderWorkflowStatus::assertMutable($next);
        }
        $order->loadMissing(['shipment.warehouse', 'operationalMarkers']);
        if ((int) $order->workflow_revision !== $revision) {
            throw new HttpException(409, '订单进度已变化，请刷新后重试。');
        }
        if ($order->archived_at !== null || $order->trashed() || $order->isOperationallyArchived()) {
            throw new HttpException(422, '归档订单不可改变状态。');
        }

        if (in_array($order->order_kind, [OrderKind::Customer, OrderKind::FulfillmentDerived], true)) {
            if (in_array($next, [...OrderManualHoldStatus::CODES, 'resolve_exception', 'resolve_manual_hold', 'cancelled'], true)) {
                return $this->core->transition($order, $next, $revision, $actor, $reason);
            }
            // Auxiliary codes in the status dropdown are NOT optional chips.
            // They now select one core operational hold and one work queue.

            if (! in_array($next, ['sending', 'out_of_stock', 'resolve_stockout'], true)) {
                throw new HttpException(409, '此进度必须通过实际业务操作完成，不能直接修改标签。');
            }
            $shipment = $order->shipment;
            $isFactory = strtoupper(trim((string) $shipment?->warehouse?->code)) === 'FACTORY';
            $isOversea = OverseaWarehouseReviewService::canonicalWarehouseCode($shipment?->warehouse?->code) !== null;
            if ($next === 'resolve_stockout') {
                if ($isFactory) {
                    if (! $actor->can('domestic.review')) throw new HttpException(403, '仅国内商务可解除中国工厂缺货。');
                    $this->domestic->decide($order, 'restock', null, $actor);
                    return $order->fresh(['shipment', 'operationalMarkers']);
                }
                if ($isOversea) {
                    if (! $this->reviewAccess->allows($actor)) throw new HttpException(403, '仅海外审核人员可解除海外仓缺货。');
                    $this->oversea->restock($order, $actor);
                    return $order->fresh(['shipment', 'operationalMarkers']);
                }
                return $this->core->transition($order, 'resolve_stockout_unallocated', $revision, $actor);
            }
            $stockout = $order->workflow_status === 'out_of_stock'
                || $order->operationalMarkers->contains('marker', 'domestic_stockout');
            $approved = $order->operationalMarkers->contains('marker', 'domestic_reviewed');
            if ($next === 'out_of_stock' && ! $isFactory) {
                if ($isOversea) {
                    if (! $this->reviewAccess->allows($actor)) throw new HttpException(403, '仅海外审核人员可标记海外仓缺货。');
                    $this->oversea->stockout($order, $actor);
                    return $order->fresh(['shipment', 'operationalMarkers']);
                }
                return $this->core->transition($order, 'out_of_stock_unallocated', $revision, $actor);
            }
            if ($next === 'sending' && ! $shipment?->warehouse_id) {
                throw new HttpException(409, '订单尚未分仓，不能直接标记待发货。');
            }

            if (in_array($next, ['sending', 'out_of_stock'], true) && $isFactory) {
                if ($shipment->shipment_status !== 'pending'
                    || $order->order_status !== OrderStatus::PendingShipment
                    || filled($shipment->tracking_number) || $shipment->shipped_at !== null) {
                    throw new HttpException(409, '订单已有真实发货记录，不能直接更改为待发或缺货。');
                }
                if ($next === 'out_of_stock' && $stockout) {
                    return $order->fresh(['shipment', 'operationalMarkers']);
                }
                if ($next === 'sending' && $approved && ! $stockout) {
                    return $this->annotations->set($order, 'sending', $revision, $actor);
                }
                if (! $actor->can('domestic.review')) {
                    throw new HttpException(403, '仅国内商务审核人员可以确认有货或缺货。');
                }
                $this->domestic->decide($order, $next === 'sending' ? 'approve' : 'stockout', null, $actor);

                return $order->fresh(['shipment', 'operationalMarkers']);
            }

            if ($next === 'out_of_stock' && $shipment?->shipment_status !== null
                && $shipment->shipment_status !== 'pending') {
                throw new HttpException(409, '已有出库事实的订单不能直接标记缺货。');
            }
            if ($next === 'sending' && $shipment?->warehouse_id && ! $isFactory) {
                $review = $shipment->overseaReview()->first();
                if (! $review || $review->status !== 'approved'
                    || (int) $review->reviewed_warehouse_id !== (int) $shipment->warehouse_id
                    || $shipment->shipment_status !== 'pending') {
                    throw new HttpException(409, '海外仓订单需要先由 Dede 审核，不能直接设为待发货。');
                }
            }
            if ($next === 'finished' && $shipment?->shipment_status !== 'completed') {
                throw new HttpException(409, '真实履约尚未完成，不能手动标记完结。');
            }
            if ($next === 'sending' && $shipment
                && $shipment->shipment_status !== 'pending') {
                throw new HttpException(409, '订单已有真实发货记录，不能直接变回待发货。');
            }
        }

        // Preserve legacy KOL business phases; never write a cosmetic cancelled state.
        if (in_array($next, ['cancelled', 'resolve_exception', 'resolve_manual_hold', 'resolve_stockout'], true)) {
            throw new HttpException(409, '该订单类型不能通过下拉框进行此项核心状态变更。');
        }
        return $this->annotations->set($order, $next, $revision, $actor);
    }
}
