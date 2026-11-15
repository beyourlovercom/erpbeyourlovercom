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
        if (! in_array($next, ['resolve_exception', 'resolve_stockout', 'resolve_manual_hold', 'pending_allocation'], true)) {
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
            if (in_array($next, [...OrderManualHoldStatus::PRIMARY_CODES, 'resolve_exception', 'resolve_manual_hold', 'cancelled'], true)) {
                return $this->core->transition($order, $next, $revision, $actor, $reason);
            }
            // Every manual classification is already handled by the single
            // core transition above. The only special dropdown action below
            // is a real warehouse "已到货" review, not a status label.
            if ($next !== 'resolve_stockout') {
                throw new HttpException(409, '实际发货、已完成等状态须通过对应业务操作完成。');
            }
            $shipment = $order->shipment;
            $isFactory = strtoupper(trim((string) $shipment?->warehouse?->code)) === 'FACTORY';
            $isOversea = OverseaWarehouseReviewService::canonicalWarehouseCode($shipment?->warehouse?->code) !== null;
            if ($isFactory) {
                if (! $actor->can('domestic.review')) {
                    throw new HttpException(403, '仅国内商务可执行仓库已到货审核。');
                }
                $this->domestic->decide($order, 'restock', null, $actor);

                return $order->fresh(['shipment', 'operationalMarkers']);
            }
            if ($isOversea) {
                if (! $this->reviewAccess->allows($actor)) {
                    throw new HttpException(403, '仅海外审核人员可执行仓库已到货审核。');
                }
                $this->oversea->restock($order, $actor);

                return $order->fresh(['shipment', 'operationalMarkers']);
            }

            return $this->core->transition($order, 'resolve_stockout_unallocated', $revision, $actor);
        }

        // Preserve legacy KOL business phases; never write a cosmetic cancelled state.
        if (in_array($next, ['cancelled', 'pending_allocation', 'resolve_exception', 'resolve_manual_hold', 'resolve_stockout'], true)) {
            throw new HttpException(409, '该订单类型不能通过下拉框进行此项核心状态变更。');
        }

        return $this->annotations->set($order, $next, $revision, $actor);
    }
}
