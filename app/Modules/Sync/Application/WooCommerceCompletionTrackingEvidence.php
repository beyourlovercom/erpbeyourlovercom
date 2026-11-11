<?php

namespace App\Modules\Sync\Application;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingBatchInspector;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;

/**
 * Fail closed before ERP writes Woo Completed: ERP shipping evidence AND the
 * current AfterShip packages on Woo must cover every active fulfillment.
 *
 * Read-only; never touches order status, shipments, emails or sync queues.
 */
final class WooCommerceCompletionTrackingEvidence
{
    public function __construct(private readonly WooCommerceTrackingGateway $gateway) {}

    /** @return array{verified:bool,reason:string} */
    public function check(Order $root, int $providerOrderId): array
    {
        if (! $this->gateway instanceof WooCommerceTrackingBatchInspector) {
            return ['verified' => false, 'reason' => 'remote_tracking_inspector_unavailable'];
        }

        $root->load(['shipment', 'originalFulfillmentChildren.shipment']);
        $segments = $root->originalFulfillmentChildren->isNotEmpty()
            ? $root->originalFulfillmentChildren
            : collect([$root]);

        $expected = [];
        foreach ($segments as $segment) {
            $shipment = $segment->shipment;
            if ($shipment && strtolower(trim((string) $shipment->shipment_status)) === 'cancelled') {
                continue;
            }
            $status = strtolower(trim((string) $shipment?->shipment_status));
            $tracking = trim((string) $shipment?->tracking_number);
            if (! in_array($status, ['shipped', 'exception', 'completed'], true)
                || $tracking === '' || ! filled($shipment?->carrier) || ! $shipment?->shipped_at) {
                return ['verified' => false, 'reason' => 'erp_unshipped_or_missing_tracking'];
            }
            $expected[$tracking] = true;
        }

        if ($expected === []) {
            return ['verified' => false, 'reason' => 'no_active_shipped_package'];
        }
        $snapshot = $this->gateway->inspectTrackingBatch([$providerOrderId])[$providerOrderId] ?? null;
        if (! is_array($snapshot) || ! is_array($snapshot['tracking_items'] ?? null)) {
            return ['verified' => false, 'reason' => 'remote_tracking_inspection_unavailable'];
        }

        foreach ($snapshot['tracking_items'] as $package) {
            $number = trim((string) ($package['tracking_number'] ?? ''));
            $provider = trim((string) ($package['provider'] ?? ''));
            if ($number !== '' && $provider !== '' && isset($expected[$number])) {
                unset($expected[$number]);
            }
        }
        if ($expected !== []) {
            // Do not include raw tracking numbers in operational summaries.
            return ['verified' => false, 'reason' => 'woo_package_tracking_not_yet_published'];
        }
        return ['verified' => true, 'reason' => 'all_active_packages_verified_on_woo'];
    }
}
