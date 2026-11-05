<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\WooCommerceTrackingOutboundService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class LogisticsTrackingImmediateDispatch
{
    public function __construct(private readonly WooCommerceTrackingOutboundService $tracking) {}

    /** @return array{status:string} */
    public function afterShipmentSaved(Order $order, ?Authenticatable $actor): array
    {
        if (! config('tracking_sync_v2.immediate_enabled', false)) {
            return ['status' => 'disabled'];
        }

        $mode = (string) config('tracking_sync_v2.immediate_mode', 'off');
        if (! in_array($mode, ['canary', 'general'], true)) {
            return ['status' => 'disabled'];
        }
        if ($mode === 'canary') {
            $raw = (string) config('tracking_sync_v2.immediate_canary_order_ids', '');
            $ids = array_map('trim', explode(',', $raw));
            $allowed = array_filter($ids, static fn (string $id): bool => ctype_digit($id) && (int) $id > 0
            );
            if (! in_array((string) $order->getKey(), $allowed, true)) {
                return ['status' => 'not_allowlisted'];
            }
        }

        // Called ONLY after the successful shipment transaction has returned.
        $order = $order->fresh(['shipment', 'items', 'externalReferences', 'fulfillmentParent.externalReferences']);
        if (! $order) {
            return ['status' => 'unavailable'];
        }
        $root = $order->fulfillment_parent_id ? $order->fulfillmentParent : $order;
        $source = $root?->source_type instanceof \BackedEnum
            ? $root->source_type->value : (string) ($root?->source_type ?? '');
        $shipment = $order->shipment;
        if ($source !== 'woocommerce' || $shipment?->shipment_status !== 'shipped'
            || ! filled($shipment->tracking_number) || ! filled($shipment->carrier)
            || ! $shipment->shipped_at) {
            return ['status' => 'not_eligible'];
        }

        try {
            $result = $this->tracking->pushOne($order, $actor);

            return ['status' => (string) ($result['status'] ?? 'failed')];
        } catch (Throwable $e) {
            // Do not roll back a real shipment or leak remote gateway errors into the UI.
            Log::warning('tracking_sync_v2.immediate_outbound_failed', [
                'order_id' => (int) $order->getKey(),
                'exception_type' => class_basename($e),
            ]);

            return ['status' => 'failed'];
        }
    }
}
