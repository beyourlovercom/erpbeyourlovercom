<?php

namespace App\Modules\Logistics\Application\ShipOut;

use App\Modules\Logistics\Application\OverseaFiles\WarehouseOrderReference;
use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutProductionReadOnlyGateway;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Read-only reconciliation of an existing one-shot attempt. Async ACK or
 * an OMS row's existence NEVER sets ERP shipment status or Tracking.
 * No POST, no cron, no Woo/AfterShip writes and no retries.
 */
final class ShipOutProductionCanaryReconcileService
{
    public function __construct(
        private readonly ShipOutProductionReadOnlyGateway $reader,
        private readonly WarehouseOrderReference $references,
    ) {}

    /** @return array<string,mixed> */
    public function inspect(int $erpOrderId): array
    {
        if (! app()->environment('production')
            || ! (bool) config('shipout.production_read_enabled', false)) {
            throw new RuntimeException('SHIPOUT_R10_PRODUCTION_READ_DISABLED');
        }
        if (trim((string) config('shipout.production_warehouse_id', '')) !== '1265864414750314497'
            || trim((string) config('shipout.production_client_org_id', '')) !== '57893'
            || $erpOrderId < 1) {
            throw new RuntimeException('SHIPOUT_R10_IDENTITY_DENIED');
        }
        try {
            $attempt = DB::table('shipout_dispatch_attempts')
                ->where('order_id', $erpOrderId)->first([
                    'order_no', 'provider_warehouse_id', 'status', 'provider_order_id',
                ]);
        } catch (\Throwable) {
            throw new RuntimeException('SHIPOUT_R10_ATTEMPT_LEDGER_UNAVAILABLE');
        }
        $order = Order::query()->findOrFail($erpOrderId);
        $reference = $this->references->warehouse($order);
        if (! $attempt) {
            // Direct signed GET even when no local attempt exists. Never creates a ledger,
            // never authorizes automatic send, and never claims the order is shipped.
            $lookup = $this->reader->orderPage('1265864414750314497', $reference, 1, 10);

            return [
                'order_id' => $erpOrderId,
                'order_no' => $reference,
                'attempt_status' => 'never_submitted',
                'provider_order_id' => count($lookup['records']) === 1
                    ? $lookup['records'][0]['orderId'] : null,
                'provider_status' => count($lookup['records']) === 1
                    ? $lookup['records'][0]['status'] : null,
                'reconciliation' => $lookup['total'] === 0 && $lookup['records'] === []
                    ? 'NO_ERP_ATTEMPT_AND_NO_OMS_ORDER'
                    : 'OMS_ORDER_WITHOUT_ERP_LEDGER_MANUAL_REVIEW',
                'shipped' => false, 'tracking_apply' => false,
            ];
        }
        if ((string) $attempt->provider_warehouse_id !== '1265864414750314497') {
            throw new RuntimeException('SHIPOUT_R10_ORDER_REFERENCE_CHANGED');
        }
        if ($reference === '' || ! hash_equals((string) $attempt->order_no, $reference)) {
            throw new RuntimeException('SHIPOUT_R10_ORDER_REFERENCE_CHANGED');
        }

        $result = $this->reader->orderPage('1265864414750314497', $reference, 1, 10);
        if ($result['total'] === 0 && $result['records'] === []) {
            return [
                'order_id' => $erpOrderId,
                'order_no' => $reference,
                'attempt_status' => (string) $attempt->status,
                'provider_order_id' => $attempt->provider_order_id ?: null,
                'provider_status' => null,
                'reconciliation' => 'NOT_YET_VISIBLE_MANUAL_REVIEW',
                'shipped' => false,
                'tracking_apply' => false,
            ];
        }
        if ($result['total'] !== 1 || count($result['records']) !== 1) {
            throw new RuntimeException('SHIPOUT_R10_PROVIDER_ORDER_AMBIGUOUS');
        }
        $provider = $result['records'][0];
        if ($provider['orderNO'] !== $reference
            || (filled($attempt->provider_order_id)
                && ! hash_equals((string) $attempt->provider_order_id, (string) $provider['orderId']))) {
            throw new RuntimeException('SHIPOUT_R10_PROVIDER_ORDER_IDENTITY_MISMATCH');
        }

        // ShipOut statuses 6/11 are cancelled/aborted (not outbound-ready).
        // An async API ACK records a created OMS order, not warehouse shipping.
        // Keep this lookup strictly read-only, including cancellation cases.
        $cancelled = in_array($provider['status'] ?? null, [6, 11], true);

        return [
            'order_id' => $erpOrderId,
            'order_no' => $reference,
            'attempt_status' => (string) $attempt->status,
            'provider_order_id' => (string) $provider['orderId'],
            'provider_status' => $provider['status'] ?? null,
            'reconciliation' => $cancelled
                ? 'PROVIDER_CANCELLED_OR_ABORTED_MANUAL_REVIEW'
                : 'ORDER_VISIBLE_MANUAL_REVIEW_ONLY',
            'provider_failure_code' => $cancelled
                ? ($provider['cancel_category'] ?? 'PROVIDER_CANCELLED_OTHER')
                : null,
            'shipped' => false,
            'tracking_apply' => false,
        ];
    }
}
