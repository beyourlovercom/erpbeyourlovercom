<?php

namespace App\Modules\Logistics\Application\ShipOut;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * R12 OMS evidence: signed provider GET only, sanitized append-only readback.
 * Never changes the original ACK, shipment status or Tracking; never sends.
 */
final class ShipOutProductionOmsReadbackService
{
    public function __construct(private readonly ShipOutProductionCanaryReconcileService $reconcile) {}

    /** @return array<string,mixed> */
    public function inspectAndRecord(int $orderId): array
    {
        try {
            return $this->recordResult($orderId, $this->reconcile->inspect($orderId));
        } catch (\Throwable $error) {
            $this->recordUnavailable($orderId);
            throw $error;
        }
    }

    /** @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public function recordResult(int $orderId, array $result): array
    {
        if (($result['attempt_status'] ?? null) === 'never_submitted') {
            return $result; // Cannot silently create evidence for an unrelated order.
        }
        if (isset($result['order_id']) && (int) $result['order_id'] !== $orderId) {
            throw new RuntimeException('SHIPOUT_R12_OMS_ORDER_ID_MISMATCH');
        }
        $attempt = DB::table('shipout_dispatch_attempts')->where('order_id', $orderId)
            ->where('provider_warehouse_id', ShipOutProductionDispatchState::MOREPLUS_PROVIDER_WAREHOUSE_ID)
            ->first(['provider_order_id']);
        if (! $attempt) {
            throw new RuntimeException('SHIPOUT_R12_ORIGINAL_ATTEMPT_REQUIRED');
        }
        $providerId = trim((string) ($result['provider_order_id'] ?? ''));
        if (filled($attempt->provider_order_id) && filled($providerId)
            && ! hash_equals((string) $attempt->provider_order_id, $providerId)) {
            throw new RuntimeException('SHIPOUT_R12_OMS_ID_MISMATCH');
        }
        $previous = DB::table('shipout_oms_readbacks')->where('order_id', $orderId)
            ->orderByDesc('id')->first(['provider_order_id', 'state', 'reason_code', 'checked_at']);
        if ($previous && $previous->provider_order_id && $providerId !== (string) $previous->provider_order_id) {
            throw new RuntimeException('SHIPOUT_R12_OMS_ID_MISMATCH');
        }

        $reconciliation = (string) ($result['reconciliation'] ?? '');
        $status = $result['provider_status'] ?? null;
        $state = 'unverified';
        $reason = null;
        if ($reconciliation === 'PROVIDER_CANCELLED_OR_ABORTED_MANUAL_REVIEW') {
            $state = 'cancelled';
            $candidate = (string) ($result['provider_failure_code'] ?? '');
            $reason = in_array($candidate, [
                'PROVIDER_DUPLICATE_ORDER',
                'PROVIDER_EZEESHIP_SHIPPING_ERROR',
                'PROVIDER_CANCELLED_OTHER',
            ], true) ? $candidate : 'PROVIDER_CANCELLED_OTHER';
        } elseif ($reconciliation === 'ORDER_VISIBLE_MANUAL_REVIEW_ONLY' && is_int($status)) {
            // The current observed Production response is numeric OMS code 3.
            // Unknown codes cannot establish warehouse acceptance. Draft 0
            // and earlier stages 1/2 must never be marked issued.
            if (in_array($status, [0, 1, 2], true)) {
                $state = 'pending';
            } elseif ($status === 3) {
                // Two independent signed GETs >=120 seconds apart prove
                // continuous visibility, NOT physical outbound fulfillment.
                $verifiedAgain = $previous
                    && in_array((string) $previous->state, ['visible', 'confirmed'], true)
                    && $providerId !== ''
                    && (string) $previous->provider_order_id === $providerId
                    && ((string) $previous->state === 'confirmed'
                        || Carbon::parse($previous->checked_at)->lte(now()->subSeconds(120)));
                $state = $verifiedAgain ? 'confirmed' : 'visible';
            } else {
                $state = 'unverified';
                $reason = 'OMS_READBACK_UNRECOGNIZED_STATUS';
            }
        } elseif ($reconciliation === 'NOT_YET_VISIBLE_MANUAL_REVIEW') {
            $state = 'pending';
        }
        // Never reinstate a provider-cancelled order as valid via readback
        // oscillation. Operator must reconcile the original OMS manually.
        if ($previous && (string) $previous->state === 'cancelled') {
            $state = 'cancelled';
            $reason = $reason ?: ((string) ($previous->reason_code ?? '') ?: 'PROVIDER_CANCELLED_OTHER');
        }

        DB::table('shipout_oms_readbacks')->insert([
            'order_id' => $orderId,
            'provider_order_id' => $providerId ?: null,
            'provider_status' => is_int($status) ? $status : null,
            'state' => $state,
            'reason_code' => $reason,
            'checked_at' => now(),
            'created_at' => now(),
        ]);

        return $result + [
            'oms_persisted_state' => $state,
            'oms_checked_at' => now()->toIso8601String(),
        ];
    }

    private function recordUnavailable(int $orderId): void
    {
        try {
            $attempt = DB::table('shipout_dispatch_attempts')
                ->where('order_id', $orderId)
                ->where('provider_warehouse_id', ShipOutProductionDispatchState::MOREPLUS_PROVIDER_WAREHOUSE_ID)
                ->first(['provider_order_id']);
            if (! $attempt) {
                return;
            }
            $prior = DB::table('shipout_oms_readbacks')->where('order_id', $orderId)
                ->orderByDesc('id')->first(['state', 'reason_code']);
            $cancelled = (string) ($prior->state ?? '') === 'cancelled';
            DB::table('shipout_oms_readbacks')->insert([
                'order_id' => $orderId,
                'provider_order_id' => $attempt->provider_order_id ?: null,
                'provider_status' => null,
                'state' => $cancelled ? 'cancelled' : 'unverified',
                'reason_code' => $cancelled
                    ? ($prior->reason_code ?: 'PROVIDER_CANCELLED_OTHER')
                    : 'OMS_READBACK_UNAVAILABLE_MANUAL_CHECK',
                'checked_at' => now(),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Never replace the original provider exception or leak credentials.
        }
    }
}
