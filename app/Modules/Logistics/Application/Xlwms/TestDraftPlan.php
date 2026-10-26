<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * T2: prepare an isolated TEST draft request without posting to real OMS.
 *
 * IMPORTANT: Provider documentation does not establish that isSubmit=0
 * guarantees that an external-ERP order cannot auto-progress to warehouse
 * fulfillment. This class deliberately has NO gateway, HTTP client,
 * credentials, persistence or production send method.
 */
final class TestDraftPlan
{
    public function __construct(private readonly SingleOrderPreview $preview) {}

    /** @return array<string,mixed> */
    public function prepare(int $orderId): array
    {
        $order = Order::query()->with(['address','items','shipment.warehouse',
            'shipment.carrierService','shipment.overseaReview'])->findOrFail($orderId);
        $orderNumber = trim((string) $order->erp_order_id);
        if (! Str::startsWith($orderNumber, 'TEST-XLWMS-')
            || $order->source_type?->value !== 'manual_other'
            || $order->order_status?->value !== 'pending_shipment'
            || $order->shipment?->warehouse?->code !== 'US-SM'
            || $order->shipment?->tracking_number !== null
            || $order->items->count() !== 1
            || $order->items->first()->quantity !== 1) {
            throw new HttpException(422, 'T2 draft preparation accepts only isolated TEST-XLWMS manual orders, one SKU and no prior tracking.');
        }
        $preview = $this->preview->forOrder($order);
        if ($preview['blockers'] !== []) {
            throw new HttpException(409, 'The local preflight failed: TEST draft cannot be prepared.');
        }
        $payload = $preview['payload'];
        $payload['isSubmit'] = 0;
        $payload['referOrderNo'] = $orderNumber;
        $payload['platformOrderNo'] = $orderNumber;
        $payload['thirdOrderNo'] = 'BYL-TEST-'.$order->id;

        return [
            'mode' => 'dry_run_only',
            'test_order' => $orderNumber,
            'provider' => 'xlwms',
            'payload' => $payload,
            'draft_payload_hash' => hash('sha256', json_encode(
                $payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )),
            'remote_created' => false,
            'warehouse_fulfillment_permitted' => false,
            'remote_post_permitted' => false,
            'reason' => 'Provider has not verified that API draft cannot auto-submit or generate a live warehouse task.',
        ];
    }
}
