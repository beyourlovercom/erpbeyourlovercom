<?php

namespace App\Modules\Orders\Application;

use App\Modules\Logistics\Application\Domestic\DomesticReviewDecisionService;
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
    ) {}

    public function transition(Order $order, string $next, int $revision, Authenticatable $actor): Order
    {
        OrderWorkflowStatus::assertMutable($next);
        $order->loadMissing(['shipment.warehouse', 'operationalMarkers']);
        if ((int) $order->workflow_revision !== $revision) {
            throw new HttpException(409, '订单进度已变化，请刷新后重试。');
        }
        if ($order->archived_at !== null || $order->trashed() || $order->isOperationallyArchived()) {
            throw new HttpException(422, '归档订单不可改变状态。');
        }

        if (in_array($order->order_kind, [OrderKind::Customer, OrderKind::FulfillmentDerived], true)) {
            if (OrderAuxiliaryStatusService::isAuxiliary($next)) {
                return $this->auxiliary->add($order, $next, $revision, $actor);
            }
            if (! in_array($next, ['sending', 'out_of_stock'], true)) {
                throw new HttpException(409, '此进度必须通过实际业务操作完成，不能直接修改标签。');
            }
            $shipment = $order->shipment;
            $isFactory = strtoupper(trim((string) $shipment?->warehouse?->code)) === 'FACTORY';
            $stockout = $order->workflow_status === 'out_of_stock'
                || $order->operationalMarkers->contains('marker', 'domestic_stockout');
            $approved = $order->operationalMarkers->contains('marker', 'domestic_reviewed');
            if ($next === 'out_of_stock' && ! $isFactory) {
                throw new HttpException(409, '缺货操作需通过已分配的国内商务审核流程。');
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

        // Non-fulfillment stages and existing KOL business phases remain
        // staff annotations in R0-R3; R4 migrates auxiliary flags.
        return $this->annotations->set($order, $next, $revision, $actor);
    }
}
