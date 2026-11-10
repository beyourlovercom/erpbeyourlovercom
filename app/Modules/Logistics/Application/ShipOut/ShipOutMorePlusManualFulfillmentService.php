<?php

namespace App\Modules\Logistics\Application\ShipOut;

use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Logistics\Application\ShipmentWebsiteSyncHandoff;
use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Dede manually finishes an existing MorePlus provider exception.
 * This service NEVER contacts ShipOut and NEVER triggers Woo/AfterShip.
 * Only a real outbound reference and real carrier tracking can mark shipped.
 * Existing immutable ShipOut POST ledgers remain untouched.
 */
final class ShipOutMorePlusManualFulfillmentService
{
    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly ShipmentWebsiteSyncHandoff $syncHandoff,
    ) {}

    public function confirm(Order $order, string $tracking, string $outboundReference, Authenticatable $actor): array
    {
        $order->load(['shipment.warehouse', 'shipment.overseaReview']);
        $shipment = $order->shipment;
        $review = $shipment?->overseaReview;
        if ($order->isOperationallyArchived() || $order->order_status?->value !== 'pending_shipment'
            || $shipment?->shipment_status !== 'pending'
            || strcasecmp((string) ($shipment?->warehouse?->code ?? ''), 'US-LA') !== 0
            || $review?->status !== 'approved'
            || (int) $review->reviewed_warehouse_id !== (int) $shipment->warehouse_id) {
            throw new RuntimeException('SHIPOUT_R9_MANUAL_NOT_APPROVED');
        }
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/+\-]{7,127}\z/D', trim($tracking))
            || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._\-]{2,89}\z/D', trim($outboundReference))) {
            throw new RuntimeException('SHIPOUT_R9_MANUAL_PROOF_REQUIRED');
        }
        if (! DB::table('shipout_dispatch_attempts')->where('order_id', $order->id)->exists()) {
            throw new RuntimeException('SHIPOUT_R9_MANUAL_NO_PRIOR_ATTEMPT');
        }
        if (DB::table('shipout_production_batch_items')
            ->where('order_id', $order->id)
            ->where('result_code', 'PROVIDER_DUPLICATE_ORDER')->exists()) {
            // C42149: a confirmed provider duplicate cannot become another
            // automatic or manual fulfillment without a separate investigation.
            throw new RuntimeException('SHIPOUT_R9_CONFIRMED_DUPLICATE_BLOCKED');
        }
        if (DB::table('shipments')->where('tracking_number', trim($tracking))
            ->where('order_id', '!=', $order->id)->exists()) {
            throw new RuntimeException('SHIPOUT_R9_TRACKING_ALREADY_USED');
        }

        // Existing ShipmentService locks the order and stores the operator in
        // audit; ensurePending delegates subsequent Woo sync to the sync module.
        $updated = $this->shipments->markShipped($order, [
            'warehouse_id' => $shipment->warehouse_id,
            'carrier' => (string) ($shipment->carrier ?: 'USPS'),
            'service' => (string) ($shipment->service ?: 'Priority'),
            'tracking_number' => trim($tracking),
            'shipped_at' => now()->utc()->toIso8601String(),
            'logistics_note' => trim(
                (trim((string) $shipment->logistics_note) !== ''
                    ? trim((string) $shipment->logistics_note).' | ' : '')
                .'MorePlus 人工出库单：'.trim($outboundReference)
                .'；既有 ShipOut 提交流水保留，禁止自动重发'
            ),
        ], $actor);

        OverseaWarehouseReview::query()->where('order_id', $order->id)->update(['progress' => 'shipped']);

        // Reuse the shared, audited 30-minute Sync OS handoff used by
        // overseas tracking imports and 3SM manual confirmation.
        $websiteSync = $this->syncHandoff->afterShipmentSaved($updated);

        return [
            'order_id' => (int) $order->id,
            'shipment_status' => $updated->shipment?->shipment_status,
            'tracking_number' => $updated->shipment?->tracking_number,
            'website_sync' => $websiteSync['status'],
            'provider_post' => false,
        ];
    }
}
