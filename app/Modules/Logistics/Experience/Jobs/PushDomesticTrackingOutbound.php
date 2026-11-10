<?php

namespace App\Modules\Logistics\Experience\Jobs;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\WooCommerceTrackingOutboundService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Runs after ERP shipment persistence; never blocks a human batch apply request. */
final class PushDomesticTrackingOutbound implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 75;

    public function __construct(public readonly int $orderId) {}

    public function handle(WooCommerceTrackingOutboundService $tracking): void
    {
        $order = Order::query()->with([
            'shipment', 'items', 'externalReferences', 'fulfillmentParent.externalReferences',
        ])->find($this->orderId);
        if (! $order || $order->shipment?->shipment_status !== 'shipped'
            || blank($order->shipment->tracking_number) || blank($order->shipment->carrier)) {
            return;
        }

        $root = $order->fulfillment_parent_id ? $order->fulfillmentParent : $order;
        $source = $root?->source_type instanceof \BackedEnum
            ? $root->source_type->value : (string) ($root?->source_type ?? '');
        if ($source !== 'woocommerce') {
            return;
        }

        try {
            $result = $tracking->pushOne($order, null);
            if (! in_array((string) ($result['status'] ?? ''), ['completed', 'processing', 'retry_wait', 'retry_scheduled'], true)) {
                Log::warning('domestic.tracking.async_incomplete', [
                    'order_id' => $this->orderId,
                    'status' => (string) ($result['status'] ?? 'unknown'),
                ]);
            }
        } catch (Throwable $e) {
            // The existing periodic outbox reconciliation remains available.
            Log::warning('domestic.tracking.async_failed', [
                'order_id' => $this->orderId,
                'exception_class' => $e::class,
            ]);
        }
    }
}
