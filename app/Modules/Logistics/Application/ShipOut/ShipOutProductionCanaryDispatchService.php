<?php

namespace App\Modules\Logistics\Application\ShipOut;

use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutProductionCanaryOutboundTransport;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Privileged internal one-shot Canary service. No route, queue, scheduled
 * command, or Production binding exposes this class as an execution entry.
 *
 * The provider can create an outbound even when our response times out.
 * Attempt identity is durably reserved before POST and is NEVER retried.
 */
final class ShipOutProductionCanaryDispatchService
{
    public function __construct(
        private readonly ShipOutProductionCanaryPreflight $preflight,
        private readonly ShipOutProductionCanaryGate $gate,
        private readonly ShipOutProductionCanaryOutboundTransport $transport,
    ) {}

    /** @return array<string,mixed> */
    public function preview(int $erpOrderId, string $shipDate): array
    {
        return $this->preflight->preview($erpOrderId, $shipDate);
    }

    /** @return array{status:string,provider_order_id:?string,result_code:string} */
    public function dispatchOne(
        int $erpOrderId,
        string $shipDate,
        string $expectedPayloadHash,
        string $typedConfirmation,
    ): array {
        // New signed real-stock and provider duplicate GETs on every attempt.
        $preflight = $this->preflight->build($erpOrderId, $shipDate);
        $summary = $preflight['summary'];
        $payload = $preflight['payload'];

        $this->gate->assertExecutionAuthorized(
            $erpOrderId,
            $summary['order_no'],
            $summary['warehouse_id'],
            $expectedPayloadHash,
            $summary['payload_hash'],
            $typedConfirmation,
        );

        // A once-per-order and once-per-warehouse-reference durable lock.
        // Never hold a DB transaction open across network I/O.
        try {
            $inserted = DB::table('shipout_dispatch_attempts')->insertOrIgnore([
                'order_id' => $erpOrderId,
                'order_no' => $summary['order_no'],
                'provider_warehouse_id' => $summary['warehouse_id'],
                'payload_hash' => $summary['payload_hash'],
                'status' => 'in_flight',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable) {
            throw new RuntimeException('SHIPOUT_R10_ATTEMPT_LEDGER_UNAVAILABLE');
        }
        if ($inserted !== 1) {
            throw new RuntimeException('SHIPOUT_R10_DUPLICATE_ATTEMPT_BLOCKED');
        }

        try {
            $result = $this->transport->submitOne(
                $erpOrderId, $payload, $expectedPayloadHash, $typedConfirmation
            );
        } catch (\Throwable) {
            // The request may have reached the provider even if timeout or
            // response parsing failed. Keep the unique attempt even if the DB
            // becomes unavailable while marking it uncertain; never retry.
            try {
                DB::table('shipout_dispatch_attempts')
                    ->where('order_id', $erpOrderId)
                    ->whereIn('status', ['in_flight', 'sent_once'])
                    ->update([
                        'status' => 'uncertain',
                        'result_code' => 'MANUAL_PROVIDER_RECONCILIATION_REQUIRED',
                        'updated_at' => now(),
                    ]);
            } catch (\Throwable) {
                // Keep the original, durable in_flight/sent_once lock.
            }
            throw new RuntimeException('SHIPOUT_R10_SUBMISSION_OUTCOME_UNKNOWN');
        }

        try {
            $updated = DB::table('shipout_dispatch_attempts')
                ->where('order_id', $erpOrderId)->where('status', 'sent_once')
                ->update([
                    'status' => $result['status'],
                    'provider_order_id' => $result['provider_order_id'],
                    'result_code' => $result['result_code'],
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('SHIPOUT_R10_RESULT_RECORD_UNCERTAIN');
            }
        } catch (\Throwable) {
            // Already sent at most once. Ledger remains persisted and blocked.
            throw new RuntimeException('SHIPOUT_R10_RESULT_RECORD_UNCERTAIN');
        }

        // Only a response acknowledgement or explicit rejection, never a
        // tracking write or status change to ERP/Woo/AfterShip.
        return $result;
    }
}
