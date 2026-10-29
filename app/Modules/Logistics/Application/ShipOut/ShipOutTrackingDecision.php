<?php

namespace App\Modules\Logistics\Application\ShipOut;

use RuntimeException;

/**
 * Pure status/readback decision. Not connected to ERP shipment or Woo writes.
 * Provider Order.status=4, outboundStatus=4, nonempty tracking and shippedTime
 * must ALL be confirmed before a human-reviewed ERP shipment update.
 */
final class ShipOutTrackingDecision
{
    /** @param array<string,mixed> $detail */
    public function evaluate(array $detail): array
    {
        $status = (int) ($detail['status'] ?? -1);
        if (in_array($status, [6, 11], true)) {
            return ['status' => 'blocked', 'reason' => 'PROVIDER_CANCELLED_OR_ABORTED'];
        }
        if (! in_array($status, [2, 3, 4, 10], true)) {
            return ['status' => 'blocked', 'reason' => 'PROVIDER_UNKNOWN_ORDER_STATE'];
        }
        $shipments = $detail['shipments'] ?? null;
        if (! is_array($shipments) || ! array_is_list($shipments)) {
            throw new RuntimeException('SHIPOUT_SHIPMENTS_UNTRUSTED');
        }
        if ($shipments === []) return ['status' => 'pending', 'reason' => 'PROVIDER_NO_SHIPMENT'];
        if (count($shipments) !== 1) {
            return ['status' => 'blocked', 'reason' => 'PROVIDER_MULTISHIPMENT_MANUAL_REVIEW'];
        }
        $s = $shipments[0];
        if (! is_array($s)) throw new RuntimeException('SHIPOUT_SHIPMENTS_UNTRUSTED');
        if ((int) ($s['labelStatus'] ?? -1) === 5) {
            return ['status' => 'blocked', 'reason' => 'PROVIDER_LABEL_EXCEPTION'];
        }
        if ($status !== 4 || (int) ($s['outboundStatus'] ?? -1) !== 4) {
            return ['status' => 'pending', 'reason' => 'PROVIDER_NOT_SHIPPED_YET'];
        }
        $tracking = trim((string) ($s['trackingNumber'] ?? ''));
        $shipped = trim((string) ($s['shippedTime'] ?? ''));
        $carrier = $s['carrierId'] ?? null;
        if ($tracking === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:+\\/-]{0,127}$/', $tracking) !== 1
            || ! preg_match('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$/', $shipped)
            || ! in_array($carrier, [1, 2, 3, 4, 9], true)) {
            return ['status' => 'blocked', 'reason' => 'PROVIDER_SHIPMENT_EVIDENCE_INCOMPLETE'];
        }
        return [
            'status' => 'ready_for_erp_review',
            'reason' => 'WAREHOUSE_SHIPPED_WITH_TRACKING',
            'tracking_number' => $tracking,
            'shipped_time_utc' => $shipped,
            'carrier_id' => $carrier,
            'shipment_id' => (string) ($s['shipmentId'] ?? ''),
        ];
    }
}
