<?php

namespace App\Modules\Orders\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
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
    ) {}

    public function transition(Order $order, string $command, int $expectedRevision, Authenticatable $actor, ?string $reason = null): Order
    {
        abort_unless($actor->can('orders.edit'), 403, '没有更改订单核心状态的权限。');
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
            $activeException = OrderManualHoldStatus::selected($locked) !== null;
            $unallocated = ! $shipment?->warehouse_id;
            $unshipped = $locked->order_status === OrderStatus::PendingShipment
                && ($shipment === null || ($shipment->shipment_status === 'pending'
                    && blank($shipment->tracking_number) && $shipment->shipped_at === null));
            switch ($command) {
                case 'exception':
                case 'missing_info':
                case 'renew':
                case 'claims':
                case 'returned_follow_up':
                case 'freight_product_cost_follow_up':
                    if ($locked->order_status === OrderStatus::Cancelled) {
                        throw new HttpException(409, '已取消订单不允许重新标记其他状态。');
                    }
                    if ($shipment?->shipment_status === 'exception' && $command !== 'exception') {
                        throw new HttpException(409, '物流存在真实异常，请先完成物流复核。');
                    }
                    if ($locked->workflow_status === 'out_of_stock') {
                        throw new HttpException(409, '请先通过库存审核解除缺货，才能更改为其他状态。');
                    }
                    if ($locked->workflow_status === $command
                        && ! $locked->operationalMarkers->contains(fn ($m) => in_array($m->marker, OrderManualHoldStatus::HOLD_MARKERS, true))) {
                        return $locked;
                    }
                    // One selected status, no append-only status chips.
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
                    if ($locked->workflow_status === 'out_of_stock') return $locked;
                    $locked->workflow_status = 'out_of_stock';
                    break;
                case 'resolve_stockout_unallocated':
                    if (! $unallocated || ! $unshipped || $locked->workflow_status !== 'out_of_stock') {
                        throw new HttpException(409, '只能解除未分仓订单的人工缺货；已分仓请走仓库复核。');
                    }
                    $locked->workflow_status = null;
                    break;
                case 'cancelled':
                    if ($locked->order_status === OrderStatus::Cancelled) return $locked;
                    if ($locked->order_kind !== OrderKind::Customer
                        || ! $unallocated || ! $unshipped || $locked->originalFulfillmentChildren->isNotEmpty()
                        || $locked->fulfillmentAllocation?->status === 'submitted') {
                        throw new HttpException(409, '只能直接取消未分仓、无真实出库或分单记录的客户订单；其他订单请先履约复核。');
                    }
                    if (mb_strlen(trim((string) $reason)) < 3) {
                        throw new HttpException(422, '取消订单必须填写至少 3 个字的原因。');
                    }
                    $locked->order_status = OrderStatus::Cancelled;
                    $locked->operationalMarkers()->whereIn('marker', OrderManualHoldStatus::HOLD_MARKERS)->delete();
                    if (in_array((string) $locked->workflow_status, OrderManualHoldStatus::CODES, true)) {
                        $locked->workflow_status = null;
                    }
                    break;
                default:
                    throw new HttpException(422, '不支持的核心状态操作。');
            }

            $locked->workflow_revision = (int) $locked->workflow_revision + 1;
            $locked->save();
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
