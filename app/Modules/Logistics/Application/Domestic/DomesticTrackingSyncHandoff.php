<?php

namespace App\Modules\Logistics\Application\Domestic;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\WooCommerceTrackingOutboundService;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Record sync intent only; Sync OS executes it on its existing 30-minute schedule. */
final class DomesticTrackingSyncHandoff
{
    public function __construct(private readonly WooCommerceTrackingOutboundService $outbound) {}

    /** @return array{status:string} */
    public function afterShipmentSaved(Order $order): array
    {
        $root = $order->fulfillment_parent_id ? $order->fulfillmentParent : $order;
        $source = $root?->source_type instanceof \BackedEnum
            ? $root->source_type->value : (string) ($root?->source_type ?? '');
        if ($source !== 'woocommerce') {
            return ['status' => 'not_required'];
        }

        try {
            $result = $this->outbound->enqueueForPeriodicSync(
                $order->fresh(['shipment', 'items', 'externalReferences', 'fulfillmentParent.externalReferences'])
            );

            return ['status' => match ($result['status']) {
                'completed' => 'already_synced',
                'pending', 'processing' => 'pending',
                default => 'handoff_failed',
            }];
        } catch (Throwable $e) {
            // Actual ERP shipment is already committed. The Sync OS needs manual
            // investigation, but logistics staff must not resubmit the shipment.
            Log::warning('domestic.tracking.periodic_handoff_failed', [
                'order_id' => (int) $order->id,
                'error_class' => $e::class,
            ]);

            return ['status' => 'handoff_failed'];
        }
    }
}
