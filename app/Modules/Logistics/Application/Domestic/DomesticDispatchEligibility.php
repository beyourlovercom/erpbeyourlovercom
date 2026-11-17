<?php

namespace App\Modules\Logistics\Application\Domestic;

use App\Modules\Orders\Application\OrderManualHoldStatus;
use App\Modules\Orders\Infrastructure\Models\Order;

/**
 * Export-side invariant independent of list filters.
 * A label such as workflow_status=sending never grants physical dispatch.
 */
final class DomesticDispatchEligibility
{
    /** @return list<string> */
    public function blockers(Order $order): array
    {
        $order->loadMissing(['shipment.warehouse', 'operationalMarkers', 'procurementOrder']);
        $shipment = $order->shipment;
        if ($order->trashed() || $order->archived_at !== null || $order->isOperationallyArchived()) {
            return ['归档或删除订单禁止导出'];
        }
        if ($order->order_status?->value !== 'pending_shipment'
            || ! $shipment || $shipment->shipment_status !== 'pending'
            || $shipment->shipped_at !== null) {
            return ['该单已离开待发货队列，禁止重复导出'];
        }
        if (strtoupper((string) $shipment->warehouse?->code) !== 'FACTORY') {
            return ['订单尚未实际分配中国工厂，禁止导出'];
        }
        if ($order->procurementOrder?->origin === 'external'
            && $order->procurementOrder->released_at === null) {
            return ['外采尚未验收到货放行，禁止导出'];
        }
        if ($order->workflow_status === 'out_of_stock'
            || $order->operationalMarkers->contains(fn ($m) => in_array($m->marker, ['domestic_stockout', 'stockout'], true))) {
            return ['缺货订单禁止导出'];
        }
        if (in_array($order->workflow_status, OrderManualHoldStatus::CODES, true)
            || $order->operationalMarkers->contains(fn ($m) => in_array($m->marker, OrderManualHoldStatus::HOLD_MARKERS, true))) {
            return ['订单处于异常或人工跟进状态，禁止导出'];
        }

        return [];
    }

    public function allows(Order $order): bool
    {
        return $this->blockers($order) === [];
    }
}
