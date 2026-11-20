<?php

namespace App\Modules\Orders\Application;

use App\Modules\Logistics\Application\LogisticsReviewAccess;
use App\Modules\Logistics\Application\OverseaWarehouseReviewService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * One order/actor command catalog for every ERP order surface.
 * A status label never stands in for shipment, delivery, payment or Woo evidence.
 */
final class OrderWorkflowStatusPolicy
{
    private const CUSTOMER_COMMANDS = [
        'pending_allocation', 'pending_review', 'sending', 'finished', 'shipped',
        'completed', 'out_of_stock', 'exception', 'cancelled',
        ...OrderManualHoldStatus::CODES,
    ];

    /** @return array{can_edit:bool,options:list<array<string,mixed>>} */
    public function for(Order $order, ?Authenticatable $actor): array
    {
        $order->loadMissing(['shipment.warehouse', 'fulfillmentParent']);
        $editor = (bool) $actor?->can('orders.edit');
        $customer = in_array($order->order_kind, [OrderKind::Customer, OrderKind::FulfillmentDerived], true);
        $parentArchived = $order->fulfillmentParent?->isOperationallyArchived() ?? false;
        $blocked = $order->trashed() || $order->archived_at !== null
            || ($order->isOperationallyArchived()
                && (! $editor || $order->operational_archived_at === null || $parentArchived));
        $warehouse = strtoupper(trim((string) $order->shipment?->warehouse?->code));
        $domestic = (bool) $actor?->can('domestic.review') && $warehouse === 'FACTORY';
        $oversea = $actor !== null
            && app(LogisticsReviewAccess::class)->allows($actor)
            && OverseaWarehouseReviewService::canonicalWarehouseCode($warehouse) !== null;
        $shipment = $order->shipment;
        $hasDispatch = $shipment !== null
            && in_array($shipment->shipment_status, ['shipped', 'completed'], true)
            && $shipment->shipped_at !== null;

        $options = [];
        foreach (OrderWorkflowStatus::registry() as $code => $definition) {
            $allowed = false;
            if (! $blocked && $customer) {
                $allowed = in_array($code, self::CUSTOMER_COMMANDS, true)
                    && ($editor || ($domestic && in_array($code, ['sending', 'out_of_stock'], true))
                        || ($oversea && $code === 'out_of_stock'))
                    && ($code !== 'cancelled' || $order->order_kind === OrderKind::Customer)
                    && ($code !== 'shipped' || $hasDispatch);
            } elseif (! $blocked && $editor && ! $customer) {
                $allowed = (bool) $definition['mutable']
                    && ! in_array($code, ['cancelled', 'pending_allocation'], true);
            }
            $disabledReason = null;
            if (! $allowed) {
                $disabledReason = match (true) {
                    $blocked => '归档、回收站或上级归档订单不能直接修改工作状态',
                    ! $editor && ! $domestic && ! $oversea => '当前账号没有工作状态修改权限',
                    ! $definition['mutable'] => '此状态由真实业务事件决定，不能修改名称来生成发货、签收或支付记录',
                    $code === 'shipped' && ! $hasDispatch => '尚无真实发货记录，请先完成履约出库',
                    default => '此订单类型或当前权限不支持该项操作，请使用对应业务流程',
                };
            }
            $options[] = ['code' => $code, ...$definition,
                'mutable' => $allowed, 'disabled_reason' => $disabledReason];
        }
        $current = app(OrderWorkflowStatusProjection::class)->for($order)['code'];
        $resolve = match (true) {
            $current === 'exception' => ['resolve_exception', 'Resolve Exception', '解除异常 / 恢复实际进度'],
            in_array($current, ['missing_info', 'renew', 'claims', 'returned_follow_up', 'freight_product_cost_follow_up'], true)
                => ['resolve_manual_hold', 'Resume', '解除当前状态 / 恢复实际进度'],
            $current === 'out_of_stock' => ['resolve_stockout', 'Restock', '解除缺货'],
            default => null,
        };
        if ($customer && $resolve !== null) {
            $mayResolve = $editor && ! $blocked;
            if ($resolve[0] === 'resolve_stockout') {
                $unallocated = ! $shipment?->warehouse_id;
                $mayResolve = ! $blocked && (($unallocated && $editor)
                    || ($domestic && $warehouse === 'FACTORY') || $oversea);
            }
            $options[] = ['code' => $resolve[0], 'en' => $resolve[1], 'zh' => $resolve[2],
                'color' => 'amber', 'mutable' => $mayResolve,
                'disabled_reason' => $mayResolve ? null : '当前账号或仓库不允许解除此状态，请走对应审核流程'];
        }
        return [
            'can_edit' => collect($options)->contains(fn (array $option): bool => $option['mutable']),
            'options' => $options,
        ];
    }
}
