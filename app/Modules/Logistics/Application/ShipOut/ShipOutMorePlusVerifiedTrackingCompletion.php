<?php

namespace App\Modules\Logistics\Application\ShipOut;

use App\Modules\Inventory\Application\WarehouseLastReadStockService;
use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutProductionReadOnlyGateway;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * R14 Tracking edit: complete ERP only with fresh exact OMS+outbound proof.
 * Read-only signed ShipOut GET; no provider POST or direct Woo calls.
 */
final class ShipOutMorePlusVerifiedTrackingCompletion
{
    public function __construct(
        private readonly ShipOutProductionReadOnlyGateway $reader,
        private readonly ShipOutMorePlusManualFulfillmentService $manual,
        private readonly WarehouseLastReadStockService $lastReadStock,
    ) {}

    /** @return array{status:string,website_sync:string|null} */
    public function tryComplete(Order $order, Authenticatable $actor): array
    {
        $order->load(['shipment.warehouse', 'shipment.overseaReview']);
        $shipment = $order->shipment;
        $review = $shipment?->overseaReview;
        if (! $shipment || $shipment->shipment_status !== 'pending'
            || (string) ($shipment->warehouse?->code ?? '') !== 'US-LA'
            || $review?->status !== 'approved'
            || (int) $review->reviewed_warehouse_id !== (int) $shipment->warehouse_id
            || $order->order_status?->value !== 'pending_shipment'
            || ! preg_match('/\A(?:92|93|94)[0-9]{20}\z/D', (string) $shipment->tracking_number)) {
            return ['status' => 'awaiting_outbound_proof', 'website_sync' => null];
        }
        $attempt = DB::table('shipout_dispatch_attempts')->where('order_id', $order->id)->first([
            'status', 'order_no', 'provider_order_id', 'provider_warehouse_id',
        ]);
        $last = DB::table('shipout_oms_readbacks')->where('order_id', $order->id)
            ->orderByDesc('id')->first(['state', 'provider_order_id']);
        if (! $attempt || $attempt->status !== 'acknowledged'
            || (string) $attempt->provider_warehouse_id !== ShipOutProductionDispatchState::MOREPLUS_PROVIDER_WAREHOUSE_ID
            || ! $last || $last->state !== 'provider_shipped'
            || (string) $last->provider_order_id !== (string) $attempt->provider_order_id
            || ! filled($attempt->provider_order_id)) {
            return ['status' => 'awaiting_outbound_proof', 'website_sync' => null];
        }

        try {
            $proof = $this->reader->verifiedShippedEvidence(
                (string) $attempt->provider_warehouse_id,
                (string) $attempt->provider_order_id,
                (string) $attempt->order_no,
                (string) $shipment->tracking_number,
            );
            $result = $this->manual->confirm(
                $order, (string) $shipment->tracking_number,
                $proof['outbound_reference'], $actor, $proof['shipped_time_utc'],
            );

            // Outbound evidence is an exact signed OMS Shipped GET. Track only
            // the separate last-read reference; never decrement provider/ERP QOH.
            try {
                $this->lastReadStock->recordVerifiedMorePlusOutbound(
                    $order, (string) $attempt->provider_order_id,
                    $proof['outbound_reference'], $proof['shipped_time_utc']
                );
            } catch (\Throwable $adjustmentError) {
                // A reference-cache failure must never re-send an OMS order or
                // undo a legitimate shipment; log for reconciliation instead.
                Log::warning('MOREPLUS_LAST_READ_ADJUSTMENT_NEEDS_REVIEW', [
                    'order_id' => $order->id,
                    'code' => $adjustmentError instanceof \RuntimeException
                        ? $adjustmentError->getMessage() : 'REFERENCE_ADJUSTMENT_FAILED',
                ]);
            }

            return ['status' => 'shipped', 'website_sync' => $result['website_sync']];
        } catch (\Throwable) {
            // The Tracking draft is already saved. Never infer shipped or
            // trigger a second submission if the provider is unavailable.
            return ['status' => 'awaiting_outbound_proof', 'website_sync' => null];
        }
    }
}
