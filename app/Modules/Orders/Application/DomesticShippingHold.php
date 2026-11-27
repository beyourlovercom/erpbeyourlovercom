<?php

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Infrastructure\Models\Order;

/**
 * A temporary domestic work-queue hold is not a shipment or an inventory event.
 * The caller owns the order lock, expected revision and actor audit.
 */
final class DomesticShippingHold
{
    public const MISSING_INFO = 'domestic_hold_missing_info';

    public const TRACKING_STOCKOUT = 'domestic_hold_tracking_stockout';

    public const RESUME = 'resume_domestic_hold';

    public const CODES = [self::MISSING_INFO, self::TRACKING_STOCKOUT];

    public static function isHeld(?string $status): bool
    {
        return in_array($status, self::CODES, true);
    }

    public static function blocker(Order $order, string $command): ?string
    {
        $order->loadMissing(['shipment.warehouse', 'operationalMarkers', 'fulfillmentParent' => fn ($q) => $q->withTrashed(), 'originalFulfillmentChildren', 'procurementOrder', 'externalReferences']);
        $shipment = $order->shipment;
        $parent = $order->fulfillmentParent;
        if (! in_array($order->order_kind, [OrderKind::Customer, OrderKind::FulfillmentDerived], true)
            || $order->trashed() || $order->archived_at !== null || $order->isOperationallyArchived()
            || $parent?->trashed() || $parent?->archived_at !== null
            || $order->originalFulfillmentChildren->isNotEmpty() || $order->activeFulfillmentChildren()->exists()
            || $order->externalReferences->contains('provider', 'historical_logistics')) {
            return '仅正常运营的独立国内履约订单可冻结或恢复。';
        }
        if ($order->order_status !== OrderStatus::PendingShipment
            || $shipment?->shipment_status !== 'pending' || $shipment->shipped_at !== null
            || strtoupper(trim((string) $shipment->warehouse?->code)) !== 'FACTORY') {
            return '仅中国工厂尚未实际发货的订单可冻结或恢复。';
        }
        if ($order->procurementOrder?->origin === 'external' && $order->procurementOrder->released_at === null) {
            return '外采未到货放行，不能冻结或恢复待发货。';
        }
        if ($command === self::RESUME) {
            if (! self::isHeld($order->workflow_status)) {
                return '订单当前不在冻结发货队列。';
            }
        } elseif (! self::isHeld($command)) {
            return '不支持的冻结发货状态。';
        } elseif ($order->workflow_status !== 'sending' && ! self::isHeld($order->workflow_status)
            && ($order->workflow_status !== null || ! $order->operationalMarkers->contains('marker', 'domestic_reviewed'))) {
            return '只能从已审核的待发货订单进入冻结队列。';
        }
        if (! $order->operationalMarkers->contains('marker', 'domestic_reviewed')
            || $order->operationalMarkers->contains(fn ($m) => in_array($m->marker, ['domestic_stockout', 'stockout', ...OrderManualHoldStatus::HOLD_MARKERS], true))) {
            return '仍有未通过的仓库审核、缺货或异常，请先处理实际业务问题。';
        }
        if ($command === self::TRACKING_STOCKOUT && blank($shipment->tracking_number)) {
            return '有单号-缺货必须保留已有真实物流单号。';
        }

        return null;
    }
}
