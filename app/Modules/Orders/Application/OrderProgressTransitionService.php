<?php

namespace App\Modules\Orders\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Logistics\Application\Domestic\DomesticReviewDecisionService;
use App\Modules\Logistics\Application\LogisticsReviewAccess;
use App\Modules\Logistics\Application\OverseaWarehouseReviewService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
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
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Changing an operationally archived order's state is also an audited,
     * atomic reactivation. A failed transition must leave it archived.
     */
    public function reactivateAndTransition(Order $order, string $next, int $revision, Authenticatable $actor, ?string $reason = null): Order
    {
        abort_unless($actor->can('orders.edit'), 403, '仅运营或管理员可以恢复归档订单并修改状态。');

        return DB::transaction(function () use ($order, $next, $revision, $actor, $reason): Order {
            $locked = Order::withTrashed()->with('fulfillmentParent')
                ->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->trashed() || $locked->archived_at !== null || $locked->operational_archived_at === null) {
                throw new HttpException(409, '只有独立运营归档订单能通过修改状态恢复；回收站订单不能直接操作。');
            }
            if ($locked->fulfillmentParent?->operational_archived_at !== null
                || $locked->fulfillmentParent?->archived_at !== null) {
                throw new HttpException(409, '请先恢复已归档的主订单，再调整子订单状态。');
            }
            if ((int) $locked->workflow_revision !== $revision) {
                throw new HttpException(409, '订单状态已变化，请刷新后重试。');
            }
            $locked->loadMissing('shipment');
            $shipment = $locked->shipment;
            if (in_array($locked->order_kind, [OrderKind::Customer, OrderKind::FulfillmentDerived], true)
                && in_array($next, ['sending', 'pending_allocation', 'pending_review'], true)
                && $shipment !== null
                && ($shipment->shipment_status !== 'pending' || filled($shipment->tracking_number)
                    || $shipment->shipped_at !== null)) {
                throw new HttpException(409, '订单已有真实物流单号或发货记录，不可通过修改状态重新发货；请从履约发货创建补发/重发子单。');
            }
            $before = [
                'operational_archived_at' => $locked->operational_archived_at?->toIso8601String(),
                'operational_archived_by' => $locked->operational_archived_by,
            ];
            $locked->forceFill(['operational_archived_at' => null, 'operational_archived_by' => null])->save();
            $updated = $this->transition($locked, $next, $revision, $actor, $reason);

            // Never claim an already-shipped parcel has re-entered an exportable
            // pre-shipment queue. Real re-dispatch uses a replacement fulfillment.
            if (in_array($updated->order_kind, [OrderKind::Customer, OrderKind::FulfillmentDerived], true)
                && in_array($next, ['sending', 'pending_allocation'], true)
                && $updated->order_status !== OrderStatus::PendingShipment) {
                throw new HttpException(409, '订单已有真实发货或完成记录，不能直接重新待发货；请创建补发/重发履约订单。');
            }

            $this->audit->record(
                AuditActor::user($actor, AuditSource::Web), 'order.operational_archive_restored',
                Order::class, $locked->id, $before,
                ['operational_archived_at' => null, 'operational_archived_by' => null,
                    'target_status' => $next, 'workflow_revision' => (int) $updated->workflow_revision],
                'order_status_reactivation_v1',
            );

            return $updated->fresh(['shipment.warehouse', 'operationalMarkers']);
        });
    }

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
            if (in_array($next, [...OrderManualHoldStatus::PRIMARY_CODES, 'pending_allocation', 'pending_review', 'finished', 'shipped', 'completed', 'resolve_exception', 'resolve_manual_hold', 'cancelled'], true)) {
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
