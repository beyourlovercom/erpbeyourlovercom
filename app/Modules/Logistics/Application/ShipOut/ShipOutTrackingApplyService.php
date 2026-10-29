<?php

namespace App\Modules\Logistics\Application\ShipOut;

use App\Modules\Logistics\Application\OverseaFiles\WarehouseOrderReference;
use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutReadOnlyGateway;
use App\Modules\Orders\Infrastructure\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A separate, OFF-by-default operator-confirmed ERP tracking apply.
 * No WooCommerce outbound call: Vita keeps the existing Woo sync gate.
 * Provider data is always freshly read, never accepted from browser input.
 */
final class ShipOutTrackingApplyService
{
    public function __construct(
        private readonly ShipOutReadOnlyGateway $gateway,
        private readonly ShipOutTrackingDecision $decision,
        private readonly ShipmentService $shipments,
        private readonly WarehouseOrderReference $references,
    ) {}

    /** @return array{status:string,tracking_number:string,woo_sync:string} */
    public function apply(int $erpOrderId, string $expectedTracking, Authenticatable $actor): array
    {
        if (app()->environment('production')) {
            throw new RuntimeException('SHIPOUT_SANDBOX_FORBIDDEN_IN_PRODUCTION');
        }
        if (! (bool) config('shipout.sandbox_tracking_apply_enabled', false)
            || ! (bool) config('shipout.sandbox_read_enabled', false)) {
            throw new RuntimeException('SHIPOUT_TRACKING_APPLY_DISABLED');
        }

        $warehouseId = trim((string) config('shipout.sandbox_warehouse_id', ''));
        if ($warehouseId === '') {
            throw new RuntimeException('SHIPOUT_SANDBOX_WAREHOUSE_NOT_CONFIGURED');
        }
        $order = Order::query()->with('shipment.warehouse', 'shipment.overseaReview')
            ->findOrFail($erpOrderId);
        $shipment = $order->shipment;
        $review = $shipment?->overseaReview;
        if ($shipment?->warehouse?->code !== 'US-LA'
            || $review?->status !== 'approved'
            || (int) $review->reviewed_warehouse_id !== (int) $shipment->warehouse_id
            || $order->order_status?->value !== 'pending_shipment'
            || $shipment->shipment_status !== 'pending') {
            throw new RuntimeException('SHIPOUT_ERP_APPROVAL_OR_WAREHOUSE_BLOCKED');
        }

        $orderNo = $this->references->warehouse($order);
        $attempt = DB::table('shipout_dispatch_attempts')
            ->where('order_id', $erpOrderId)->first();
        if (! $attempt
            || ! in_array($attempt->status, ['acknowledged', 'uncertain'], true)
            || ! hash_equals((string) $attempt->order_no, $orderNo)
            || ! hash_equals((string) $attempt->provider_warehouse_id, $warehouseId)) {
            throw new RuntimeException('SHIPOUT_NO_VERIFIED_DISPATCH_ATTEMPT');
        }

        $page = $this->gateway->orderPage($warehouseId, $orderNo, 1, 10);
        if ($page['total'] !== 1 || count($page['records']) !== 1) {
            throw new RuntimeException('SHIPOUT_PROVIDER_ORDER_IDENTITY_NOT_UNIQUE');
        }
        $record = $page['records'][0];
        if (filled($attempt->provider_order_id)
            && (string) $attempt->provider_order_id !== (string) $record['orderId']) {
            throw new RuntimeException('SHIPOUT_PROVIDER_ORDER_IDENTITY_MISMATCH');
        }
        $detail = $this->gateway->orderDetail($warehouseId, $record['orderId']);
        if (! hash_equals($orderNo, (string) $detail['orderNO'])) {
            throw new RuntimeException('SHIPOUT_PROVIDER_ORDER_IDENTITY_MISMATCH');
        }
        $decision = $this->decision->evaluate($detail);
        if ($decision['status'] !== 'ready_for_erp_review') {
            throw new RuntimeException('SHIPOUT_NOT_CONFIRMED_SHIPPED');
        }
        // The submitted carrierId (9 for warehouse-selected service) is NOT
        // the actual shipped carrier. Always persist the provider's real ID.
        // Unknown carrier IDs must remain manual review, never default USPS.
        $actualCarrier = match ((int) $decision['carrier_id']) {
            1 => 'USPS', 2 => 'UPS', 3 => 'FedEx', 4 => 'DHL',
            default => null,
        };
        if ($actualCarrier === null) {
            throw new RuntimeException('SHIPOUT_CARRIER_NOT_APPROVED_FOR_AUTO_APPLY');
        }
        // Sandbox-generated mock labels and VOID placeholders are never
        // publishable WooCommerce tracking numbers.
        if (str_starts_with(strtolower((string) $decision['tracking_number']), 'mock_')
            || str_starts_with(strtoupper((string) $decision['tracking_number']), 'UATSHIPOUTVOID')) {
            throw new RuntimeException('SHIPOUT_SYNTHETIC_TRACKING_NOT_PUBLISHABLE');
        }
        if ($expectedTracking === '' || ! hash_equals($decision['tracking_number'], $expectedTracking)) {
            throw new RuntimeException('SHIPOUT_TRACKING_CONFIRMATION_MISMATCH');
        }
        // ShipOut returns a naive wall clock without a timezone suffix.
        // Refuse the ERP write unless a vendor-verified IANA timezone is set.
        $timezone = trim((string) config('shipout.sandbox_shipped_time_timezone',''));
        if ($timezone === '' || ! in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new RuntimeException('SHIPOUT_SHIPPED_TIME_TIMEZONE_UNVERIFIED');
        }
        try {
            $shippedAt = CarbonImmutable::createFromFormat(
                '!Y-m-d H:i:s', $decision['shipped_time_utc'], $timezone
            );
            if ($shippedAt === false || $shippedAt->format('Y-m-d H:i:s') !== $decision['shipped_time_utc']) {
                throw new RuntimeException('SHIPOUT_SHIPPED_TIME_UNTRUSTED');
            }
        } catch (\Throwable) {
            throw new RuntimeException('SHIPOUT_SHIPPED_TIME_UNTRUSTED');
        }

        DB::transaction(function () use ($erpOrderId, $warehouseId, $orderNo, $shippedAt, $decision, $actualCarrier, $actor): void {
            $locked = Order::query()->with('shipment.warehouse', 'shipment.overseaReview')
                ->whereKey($erpOrderId)->lockForUpdate()->firstOrFail();
            $shipment = $locked->shipment;
            $review = $shipment?->overseaReview;
            $attempt = DB::table('shipout_dispatch_attempts')
                ->where('order_id', $erpOrderId)->lockForUpdate()->first();
            if (! $attempt || $attempt->status === 'shipped_applied'
                || $attempt->provider_warehouse_id !== $warehouseId
                || $attempt->order_no !== $orderNo
                || $shipment?->warehouse?->code !== 'US-LA'
                || $shipment->shipment_status !== 'pending'
                || $review?->status !== 'approved'
                || (int) $review->reviewed_warehouse_id !== (int) $shipment->warehouse_id
                || $locked->order_status?->value !== 'pending_shipment') {
                throw new RuntimeException('SHIPOUT_APPLY_STATE_CHANGED');
            }
            $this->shipments->markShipped($locked, [
                'warehouse_id' => $shipment->warehouse_id,
                'carrier' => $actualCarrier,
                // A stored USPS service may be invalid when provider picks UPS.
                // Do not silently inherit a service belonging to another carrier.
                'service' => $actualCarrier === $shipment->carrier
                    ? $shipment->service : null,
                'tracking_number' => $decision['tracking_number'],
                // Normalize the verified provider wall-clock to UTC before
                // Eloquent date casts (SQLite/MySQL store timestamps without TZ).
                'shipped_at' => $shippedAt->utc()->toIso8601String(),
                'logistics_note' => $shipment->logistics_note,
            ], $actor);
            OverseaWarehouseReview::query()->where('order_id', $erpOrderId)
                ->where('status', 'approved')
                ->where('reviewed_warehouse_id', $shipment->warehouse_id)
                ->update(['progress' => 'shipped']);
            DB::table('shipout_dispatch_attempts')
                ->where('id', $attempt->id)
                ->update(['status' => 'shipped_applied', 'updated_at' => now()]);
        });

        return [
            'status' => 'erp_shipment_updated',
            'tracking_number' => $decision['tracking_number'],
            'woo_sync' => 'pending_vita',
        ];
    }
}
