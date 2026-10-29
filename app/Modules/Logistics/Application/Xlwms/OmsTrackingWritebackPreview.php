<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsOrderGateway;
use App\Modules\Orders\Infrastructure\Models\Order;
use RuntimeException;

/**
 * M2 read-only shipping evidence. Does not declare a real carrier verified,
 * update ERP shipments, trigger Woo, or submit anything to AfterShip.
 */
final class OmsTrackingWritebackPreview
{
    public function __construct(private readonly XlwmsOrderGateway $oms) {}

    /** @return array<string,mixed> */
    public function forOrder(int $orderId): array
    {
        $order = Order::query()->with('shipment.warehouse')->findOrFail($orderId);
        $shipment = $order->shipment;
        if (! $shipment || $shipment->warehouse?->code !== 'US-SM') {
            throw new RuntimeException('M2_WAREHOUSE_MISMATCH');
        }
        $attempt = XlwmsDispatchAttempt::query()->where('order_id', $orderId)->first();
        $omsNo = trim((string) ($attempt?->oms_order_no ?? ''));
        if ($omsNo === '' || (int) $attempt->warehouse_id !== (int) $shipment->warehouse_id) {
            throw new RuntimeException('M2_OMS_IDENTITY_NOT_READY');
        }
        $connection = WarehouseOpenApiConnection::query()
            ->where('warehouse_id', $shipment->warehouse_id)->where('provider', 'xlwms')->firstOrFail();
        $remote = $this->oms->readExactOrderStatus($connection, $omsNo);
        if (! $remote || $remote['order_no'] !== $omsNo
            || $remote['third_order_no'] !== $attempt->third_order_no) {
            throw new RuntimeException('M2_OMS_IDENTITY_MISMATCH');
        }
        $blockers = [];
        if ($remote['status_code'] !== '3') $blockers[] = 'OMS_NOT_CONFIRMED_SHIPPED';
        if (! filled($remote['tracking_no'])) $blockers[] = 'OMS_TRACKING_MISSING';
        if (! filled($remote['carrier_raw'])) $blockers[] = 'OMS_CARRIER_MISSING';

        return [
            'order_id' => $orderId,
            'oms_order_no' => $omsNo,
            'oms_status_code' => $remote['status_code'],
            'tracking_candidate' => $remote['tracking_no'],
            'carrier_raw' => $remote['carrier_raw'],
            'blockers' => $blockers,
            'requires_manual_carrier_verification' => true,
            'can_apply' => false,
            'erp_unchanged' => true,
            'woo_unchanged' => true,
            'aftership_unchanged' => true,
        ];
    }
}
