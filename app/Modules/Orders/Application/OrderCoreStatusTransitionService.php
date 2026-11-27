<?php

namespace App\Modules\Orders\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Logistics\Application\PendingFinalSkuMaterializer;
use App\Modules\Inventory\Application\Contracts\InventoryReservationRepository;
use App\Modules\Logistics\Application\LogisticsReviewAccess;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderWorkflowStatusHistory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Local ERP core-state commands. Never synthesizes shipment/payment/Woo evidence.
 * Warehouse-owned stockout and shipment-owned transitions remain in their services.
 */
final class OrderCoreStatusTransitionService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly OrderProgressResolver $progress,
        private readonly InventoryReservationRepository $reservations,
        private readonly PendingFinalSkuMaterializer $finalSkuMaterializer,
    ) {}

    public function transition(Order $order, string $command, int $expectedRevision, Authenticatable $actor, ?string $reason = null): Order
    {
        // Keep the pre-existing Kaven/Dede dropdown access limited to their
        // respective classification choices; physical warehouse actions have
        // separate review permissions and endpoints.
        $canEdit = OrderLogisticsAuthority::allows($actor)
            || ($actor->can('domestic.review') && in_array($command, ['sending', 'out_of_stock'], true))
            || (app(LogisticsReviewAccess::class)->allows($actor)
                && $command === 'out_of_stock');
        abort_unless($canEdit, 403, '没有更改订单核心状态的权限。');

        return DB::transaction(function () use ($order, $command, $expectedRevision, $actor, $reason): Order {
            $locked = Order::withTrashed()->with(['shipment', 'operationalMarkers', 'originalFulfillmentChildren', 'fulfillmentAllocation'])
                ->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->trashed() || $locked->archived_at !== null || $locked->isOperationallyArchived()) {
                throw new HttpException(409, '归档或删除订单不允许变更核心状态。');
            }
            if ((int) $locked->workflow_revision !== $expectedRevision) {
                throw new HttpException(409, '订单状态已变化，请刷新后重试。');
            }
            if (! in_array($locked->order_kind, [OrderKind::Customer, OrderKind::FulfillmentDerived], true)) {
                throw new HttpException(409, '此操作只适用于客户履约订单。');
            }

            $shipment = $locked->shipment;
            $before = OrderManualHoldStatus::selected($locked)
                ?? $this->progress->resolve($locked) ?? 'sending';
            $activeException = in_array(OrderManualHoldStatus::selected($locked), OrderManualHoldStatus::CODES, true);
            $unallocated = ! $shipment?->warehouse_id;
            $unshipped = $locked->order_status === OrderStatus::PendingShipment
                && ($shipment === null || ($shipment->shipment_status === 'pending'
                    && blank($shipment->tracking_number) && $shipment->shipped_at === null));
            switch ($command) {
                case 'pending_allocation':
                    if ($locked->originalFulfillmentChildren->isNotEmpty()) {
                        throw new HttpException(409, '拆单订单已有独立物流记录，请先在履约发货中逐段复核后再重新分仓。');
                    }
                    // A deliberate operational reset must really return an unshipped
                    // order to Vita's allocation queue, not merely rename a label.
                    // Already-shipped evidence is never erased or made re-exportable.
                    if ($shipment && $shipment->shipment_status === 'pending'
                        && blank($shipment->tracking_number) && $shipment->shipped_at === null) {
                        $reservation = $this->reservations->activeForOrder((int) $locked->id);
                        if ($reservation) {
                            $this->reservations->release((int) $reservation['id'], (int) $actor->getAuthIdentifier(), 'staff_force_return_allocation');
                        }
                        $shipment->forceFill([
                            'warehouse_id' => null, 'warehouse_selection_source' => null,
                            'carrier' => null, 'service' => null, 'carrier_service_id' => null,
                            'carrier_selection_source' => null, 'handled_by' => null,
                        ])->save();
                        $shipment->overseaReview()->update([
                            'status' => 'pending', 'reviewed_warehouse_id' => null,
                            'reviewed_by' => null, 'reviewed_at' => null,
                        ]);
                        $locked->operationalMarkers()->whereIn('marker', ['domestic_reviewed', 'domestic_stockout'])->delete();
                        $locked->fulfillmentAllocation?->forceFill(['status' => 'rejected'])->save();
                    }
                    if (! $shipment || ($shipment->shipment_status === 'pending'
                        && blank($shipment->tracking_number) && $shipment->shipped_at === null)) {
                        $locked->order_status = OrderStatus::PendingShipment;
                    }
                    $locked->routing_warehouse_code = 'UNASSIGNED';
                    $locked->routing_revision = (int) $locked->routing_revision + 1;
                    $locked->operationalMarkers()->whereIn('marker', OrderManualHoldStatus::HOLD_MARKERS)->delete();
                    $locked->workflow_status = 'pending_allocation';
                    break;
                case 'pending_review':
                    if (! $shipment || ! $shipment->warehouse_id
                        || $shipment->shipment_status !== 'pending'
                        || filled($shipment->tracking_number) || $shipment->shipped_at !== null) {
                        throw new HttpException(409, '进入待审核需要已分仓且尚未实际出库；已发货记录不可退回仓库审核。');
                    }
                    $locked->operationalMarkers()->where('marker', 'domestic_reviewed')->delete();
                    $shipment->overseaReview()->update([
                        'status' => 'pending', 'reviewed_warehouse_id' => null,
                        'reviewed_by' => null, 'reviewed_at' => null,
                    ]);
                    $locked->order_status = OrderStatus::PendingShipment;
                    $locked->operationalMarkers()->whereIn('marker', OrderManualHoldStatus::HOLD_MARKERS)->delete();
                    $locked->workflow_status = 'pending_review';
                    break;
                case 'sending':
                case 'out_of_stock':
                case 'exception':
                case 'missing_info':
                case 'renew':
                case 'claims':
                case 'returned_follow_up':
                case 'freight_product_cost_follow_up':
                    // Manual status is the only authority for classification.
                    // Warehouse review / allocation / tracking facts are NEVER
                    // changed by choosing a label. Real physical actions use
                    // their own audited endpoints and safety checks.
                    if ($locked->order_status === OrderStatus::Cancelled
                        && (! $shipment || ($shipment->shipment_status === 'pending'
                            && blank($shipment->tracking_number) && $shipment->shipped_at === null))) {
                        $locked->order_status = OrderStatus::PendingShipment;
                    }
                    if ($locked->workflow_status === $command && ! $locked->isDirty('order_status')
                        && ! $locked->operationalMarkers->contains(fn ($m) => in_array($m->marker, OrderManualHoldStatus::HOLD_MARKERS, true))) {
                        return $locked;
                    }
                    $locked->operationalMarkers()->whereIn('marker', OrderManualHoldStatus::HOLD_MARKERS)->delete();
                    $locked->workflow_status = $command;
                    break;
                case 'resolve_exception':
                case 'resolve_manual_hold':
                    if ($shipment?->shipment_status === 'exception') {
                        throw new HttpException(409, '物流仍处于真实异常状态，请先通过物流异常处理解除。');
                    }
                    if (! $activeException) {
                        throw new HttpException(409, '订单目前没有待解除的人工状态。');
                    }
                    $locked->operationalMarkers()->whereIn('marker', OrderManualHoldStatus::HOLD_MARKERS)->delete();
                    if (in_array((string) $locked->workflow_status, OrderManualHoldStatus::CODES, true)) {
                        $locked->workflow_status = null;
                    }
                    break;
                case 'out_of_stock_unallocated':
                    if (! $unallocated || ! $unshipped || $locked->originalFulfillmentChildren->isNotEmpty()) {
                        throw new HttpException(409, '未分仓且未发货的主订单才允许直接挂起缺货。');
                    }
                    if ($shipment?->shipment_status === 'exception') {
                        throw new HttpException(409, '物流真实异常未解除，不能改为缺货。');
                    }
                    // Staff is explicitly replacing the current hold with
                    // physical stockout. Do not leave stale status chips.
                    $locked->operationalMarkers()->whereIn('marker', OrderManualHoldStatus::HOLD_MARKERS)->delete();
                    if ($locked->workflow_status === 'out_of_stock') {
                        return $locked;
                    }
                    $locked->workflow_status = 'out_of_stock';
                    break;
                case 'resolve_stockout_unallocated':
                    if (! $unallocated || ! $unshipped || $locked->workflow_status !== 'out_of_stock') {
                        throw new HttpException(409, '只能解除未分仓订单的人工缺货；已分仓请走仓库复核。');
                    }
                    $locked->workflow_status = null;
                    break;
                case 'finished':
                case 'completed':
                    $locked->order_status = OrderStatus::Completed;
                    $locked->workflow_status = null;
                    $locked->operationalMarkers()->whereIn('marker', OrderManualHoldStatus::HOLD_MARKERS)->delete();
                    break;
                case 'shipped':
                    // Do not fabricate an actual shipment or tracking number.
                    // A manual shipping state requires genuine dispatch evidence.
                    if (! $shipment || ! in_array($shipment->shipment_status, ['shipped', 'completed'], true)
                        || $shipment->shipped_at === null) {
                        throw new HttpException(409, '未找到真实发货记录，无法直接标记已发货；请先完成履约发货。');
                    }
                    $locked->order_status = OrderStatus::Shipped;
                    $locked->workflow_status = null;
                    break;
                case 'cancelled':
                    if ($locked->order_status === OrderStatus::Cancelled) {
                        return $locked;
                    }
                    if ($locked->order_kind !== OrderKind::Customer) {
                        throw new HttpException(409, '仅客户订单可通过人工权限取消。');
                    }
                    // A dispatched parcel is never erased or automatically refunded.
                    // Cancellation changes ERP customer status, not provider facts.
                    if (mb_strlen(trim((string) $reason)) < 3) {
                        throw new HttpException(422, '取消订单必须填写至少 3 个字的原因。');
                    }
                    $locked->order_status = OrderStatus::Cancelled;
                    $locked->operationalMarkers()->whereIn('marker', OrderManualHoldStatus::HOLD_MARKERS)->delete();
                    $locked->workflow_status = null;
                    break;
                default:
                    throw new HttpException(422, '不支持的核心状态操作。');
            }

            $locked->workflow_revision = (int) $locked->workflow_revision + 1;
            $locked->save();
            if (in_array($command, ['sending', 'pending_review', 'resolve_exception', 'resolve_manual_hold'], true)) {
                $this->finalSkuMaterializer->heal($locked, $actor);
            }
            $updated = $locked->fresh(['shipment.warehouse', 'operationalMarkers', 'originalFulfillmentChildren']);
            $after = OrderManualHoldStatus::selected($updated)
                ?? $this->progress->resolve($updated) ?? 'sending';
            OrderWorkflowStatusHistory::query()->create([
                'order_id' => $locked->id,
                'actor_id' => $actor->getAuthIdentifier(),
                'from_status' => $before,
                'to_status' => $after,
                'revision' => (int) $locked->workflow_revision,
            ]);
            $this->audit->record(
                AuditActor::user($actor, AuditSource::Web),
                'order.core_status_changed', Order::class, $locked->id,
                ['core_status' => $before, 'revision' => $expectedRevision],
                ['core_status' => $after, 'revision' => (int) $locked->workflow_revision,
                    'command' => $command, 'user_reason' => $reason !== null ? trim($reason) : null],
                'authorized_core_status_transition',
            );

            return $updated;
        });
    }
}
