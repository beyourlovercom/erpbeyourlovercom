<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Logistics\Application\ShipOut\ShipOutProductionCanaryDispatchService;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionCanaryReconcileService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Production V1: Dede-reviewed order workflow, isolated from ShipOut Sandbox.
 * All real actions remain OFF until a separately approved operational cutover.
 * Never exposes recipient addresses, tokens or raw provider error responses.
 */
final class ShipOutProductionV1Controller
{
    public function preflight(Order $order, ShipOutProductionCanaryDispatchService $dispatch): JsonResponse
    {
        $this->guardWarehouse($order);
        if (! $this->readEnabled()) {
            return $this->blocked('SHIPOUT_V1_READ_DISABLED', 423);
        }
        $date = now('America/Los_Angeles')->addDay()->format('Y-m-d');
        try {
            $summary = $dispatch->previewApproved((int) $order->id, $date);

            return response()->json([
                'order_id' => $summary['order_id'],
                'ship_date' => $date,
                'shipping_mode' => $summary['shipping_mode'],
                'order_no' => $summary['order_no'],
                'warehouse_id' => $summary['warehouse_id'],
                'items' => $summary['items'],
                'blockers' => [],
                'ready' => $summary['ready'],
                'payload_hash' => $summary['payload_hash'],
                'already_attempted' => false,
                'production' => true,
                'mutated' => false,
            ])->header('Cache-Control', 'no-store, private');
        } catch (\Throwable $error) {
            return $this->blocked($error instanceof RuntimeException
                ? $error->getMessage() : 'SHIPOUT_V1_PREFLIGHT_BLOCKED');
        }
    }

    public function send(
        Order $order,
        Request $request,
        ShipOutProductionCanaryDispatchService $dispatch,
    ): JsonResponse {
        $this->guardWarehouse($order);
        if (! $this->dispatchEnabled()) {
            return $this->blocked('SHIPOUT_V1_DISPATCH_DISABLED', 423);
        }
        $operatorId = (int) config('shipout.production_v1_operator_user_id', 0);
        if ($operatorId < 1 || ! $request->user()?->is_active
            || (int) $request->user()?->id !== $operatorId) {
            return $this->blocked('SHIPOUT_V1_OPERATOR_DENIED', 403);
        }
        $minOrderId = (int) config('shipout.production_v1_min_order_id', 0);
        if ($minOrderId < 1 || (int) $order->id < $minOrderId) {
            return $this->blocked('SHIPOUT_V1_OLD_ORDER_EXCLUDED', 409);
        }
        $data = $request->validate([
            'ship_date' => ['required', 'date_format:Y-m-d'],
            'shipping_mode' => ['required', 'in:9'],
            'payload_hash' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/D'],
            'confirmation' => ['required', 'string', 'max:90'],
        ]);
        if (! hash_equals('SUBMIT_SHIPOUT_ORDER_'.$order->id, $data['confirmation'])) {
            return $this->blocked('SHIPOUT_V1_CONFIRMATION_MISMATCH', 422);
        }
        try {
            $result = $dispatch->dispatchApprovedOrder(
                (int) $order->id, $data['ship_date'], $data['payload_hash'],
                $data['confirmation'],
            );

            return response()->json([
                'order_id' => (int) $order->id,
                'status' => $result['status'],
                'provider_order_id' => $result['provider_order_id'],
                'result_code' => $result['result_code'],
                'erp_shipment_unchanged' => true,
                'woo_unchanged' => true,
                'tracking_apply' => false,
            ])->header('Cache-Control', 'no-store, private');
        } catch (\Throwable $error) {
            return $this->blocked($error instanceof RuntimeException
                ? $error->getMessage() : 'SHIPOUT_V1_SUBMISSION_OUTCOME_UNKNOWN');
        }
    }

    public function status(Order $order, ShipOutProductionCanaryReconcileService $reconcile): JsonResponse
    {
        $this->guardWarehouse($order);
        if (! $this->readEnabled()) {
            return $this->blocked('SHIPOUT_V1_READ_DISABLED', 423);
        }
        try {
            $result = $reconcile->inspect((int) $order->id);

            return response()->json($result + [
                'read_only' => true,
                'erp_shipment_unchanged' => true,
                'woo_unchanged' => true,
            ])->header('Cache-Control', 'no-store, private');
        } catch (\Throwable $error) {
            return $this->blocked($error instanceof RuntimeException
                ? $error->getMessage() : 'SHIPOUT_V1_STATUS_UNAVAILABLE');
        }
    }

    private function readEnabled(): bool
    {
        return app()->environment('production')
            && (bool) config('shipout.production_read_enabled', false)
            && (bool) config('shipout.production_v1_read_enabled', false);
    }

    private function dispatchEnabled(): bool
    {
        return $this->readEnabled()
            && (bool) config('shipout.production_dispatch_enabled', false)
            && (bool) config('shipout.production_v1_dispatch_enabled', false)
            && (bool) config('shipout.production_v1_owner_approved', false);
    }

    private function guardWarehouse(Order $order): void
    {
        $order->loadMissing('shipment.warehouse');
        abort_unless(strcasecmp(
            trim((string) ($order->shipment?->warehouse?->code ?? '')), 'US-LA'
        ) === 0, 404);
    }

    private function blocked(string $reason, int $status = 409): JsonResponse
    {
        // Never emit arbitrary raw provider details or customer data.
        $known = [
            'SHIPOUT_V1_READ_DISABLED',
            'SHIPOUT_V1_DISPATCH_DISABLED',
            'SHIPOUT_V1_CONFIRMATION_MISMATCH',
            'SHIPOUT_V1_OPERATOR_DENIED',
            'SHIPOUT_V1_OLD_ORDER_EXCLUDED',
            'SHIPOUT_R10_PRIOR_ATTEMPT_BLOCKED',
            'SHIPOUT_R10_PREFLIGHT_STALE',
            'SHIPOUT_R10_PHYSICAL_SKU_NOT_AVAILABLE',
            'SHIPOUT_R10_INSUFFICIENT_AVAILABLE_STOCK',
            'SHIPOUT_R10_PROVIDER_ORDER_EXISTS',
            'SHIPOUT_R10_SUBMISSION_OUTCOME_UNKNOWN',
            'SHIPOUT_R10_NO_VERIFIED_ATTEMPT',
            'SHIPOUT_ERP_APPROVAL_OR_WAREHOUSE_BLOCKED',
            'SHIPOUT_R10_SHIPMENT_NOT_PENDING',
        ];

        return response()->json([
            'error_code' => in_array($reason, $known, true) ? $reason : 'SHIPOUT_V1_OPERATION_BLOCKED',
            'retry_same_order' => false,
            'erp_shipment_unchanged' => true,
            'woo_unchanged' => true,
        ], $status)->header('Cache-Control', 'no-store, private');
    }
}
