<?php

namespace App\Modules\Logistics\Application\ShipOut;

use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutReadOnlyGateway;
use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutSandboxOutboundTransport;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Single-order guarded sandbox dispatcher.
 *
 * PERSISTENCE BOUNDARY: atomic unique attempt is written BEFORE the network
 * POST. Any failure or timeout is irretryable until explicitly reconciled
 * against OMS provider state. NO auto requeue and NO Woo side effects.
 */
final class ShipOutSandboxDispatchService
{
    public function __construct(
        private readonly ShipOutOutboundPayloadFactory $factory,
        private readonly ShipOutReadOnlyGateway $reader,
        private readonly ShipOutSandboxOutboundTransport $transport,
        private readonly ShipOutSandboxLabelStage $labels,
        private readonly ShipOutProviderSkuStockVerifier $stock,
    ) {}

    /** @return array{status:string,provider_order_id:?string,result_code:string} */
    public function dispatchApprovedOrder(
        int $orderId, string $shipDate, ?string $expectedPayloadHash = null,
        string $shippingMode = '9', ?string $labelToken = null,
        ?int $actorId = null
    ): array
    {
        if (app()->environment('production')) {
            throw new RuntimeException('SHIPOUT_SANDBOX_FORBIDDEN_IN_PRODUCTION');
        }
        if (! (bool) config('shipout.sandbox_read_enabled', false)
            || ! (bool) config('shipout.sandbox_dispatch_enabled', false)) {
            throw new RuntimeException('SHIPOUT_SANDBOX_DISPATCH_DISABLED');
        }
        $whId = trim((string) config('shipout.sandbox_warehouse_id', ''));
        if ($whId === '') throw new RuntimeException('SHIPOUT_SANDBOX_WAREHOUSE_NOT_CONFIGURED');

        $order = Order::query()->with(['shipment.warehouse', 'shipment.overseaReview', 'address', 'items'])
            ->findOrFail($orderId);
        $label=$shippingMode==='6'
            ? $this->labels->resolve($orderId, (int)$actorId, (string)$labelToken)
            : null;
        if ($shippingMode!=='6' && $labelToken!==null) {
            throw new RuntimeException('SHIPOUT_LABEL_TOKEN_INVALID');
        }
        $payload = $this->factory->fromApprovedOrder($order, $whId, $shipDate, $shippingMode, $label);
        $payload = $this->stock->verify($order,$payload,$whId);
        $orderNo = (string) $payload[0]['orderNo'];
        if ($orderNo !== trim((string) config('shipout.sandbox_order_allowlist', ''))) {
            throw new RuntimeException('SHIPOUT_ORDER_NOT_APPROVED_IN_SANDBOX_ALLOWLIST');
        }

        // Read-before-write protects against a prior provider-side creation.
        // Failure to read MUST block a new POST.
        $found = $this->reader->orderPage($whId, $orderNo, 1, 10);
        if ($found['records'] !== [] || $found['total'] !== 0) {
            throw new RuntimeException('SHIPOUT_PROVIDER_ORDER_ALREADY_EXISTS');
        }
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        if ($expectedPayloadHash !== null && ! hash_equals($hash, $expectedPayloadHash)) {
            throw new RuntimeException('SHIPOUT_PREFLIGHT_STALE');
        }
        try {
            $inserted = DB::table('shipout_dispatch_attempts')->insertOrIgnore([
                'order_id' => $order->id,
                'order_no' => $orderNo,
                'provider_warehouse_id' => $whId,
                'payload_hash' => $hash,
                'status' => 'uncertain',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable) {
            throw new RuntimeException('SHIPOUT_ATTEMPT_LEDGER_UNAVAILABLE');
        }
        if ($inserted !== 1) throw new RuntimeException('SHIPOUT_DUPLICATE_ATTEMPT_BLOCKED');
        if ($shippingMode === '6') {
            // This is now committed to exactly one attempt: invalidate label
            // token before POST, even if provider times out or returns ERR.
            $this->labels->consume($orderId, (int)$actorId, (string)$labelToken);
        }

        try {
            $result = $this->transport->submitOne($payload);
        } catch (\Throwable) {
            // Sentinel remains uncertain on all failures, even bad HTTP.
            throw new RuntimeException('SHIPOUT_SUBMISSION_OUTCOME_UNKNOWN');
        }
        DB::table('shipout_dispatch_attempts')->where('order_id', $order->id)
            ->update([
                'status' => $result['status'],
                'provider_order_id' => $result['provider_order_id'],
                'result_code' => $result['result_code'],
                'updated_at' => now(),
            ]);
        return $result;
    }
}
