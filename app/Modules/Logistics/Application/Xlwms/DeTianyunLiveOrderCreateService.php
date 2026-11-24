<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsOrderGateway;
use App\Modules\Orders\Application\OrderManualHoldStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * One signed, provider-confirmed DE/Hannover outboundOrder/create at most once
 * for an explicitly Owner-approved live order. Never retries ambiguous creates,
 * ships a synthetic order or writes ERP shipment/Woo "shipped" on acceptance.
 */
final class DeTianyunLiveOrderCreateService
{
    public function __construct(
        private readonly SingleOrderPreview $preview,
        private readonly XlwmsOrderGateway $oms,
    ) {}

    /** @return array{status:string,code:string,oms_order_no:?string} */
    public function create(int $orderId, string $expectedFingerprint, int $actorId): array
    {
        if (! app()->environment('production', 'testing')
            || ! (bool) config('xlwms.de_order_batch_live_enabled', false)
            || ! DeTianyunDispatchAuthorization::permits($orderId, $actorId, $expectedFingerprint)) {
            throw new RuntimeException('DE_LIVE_ORDER_CREATE_NOT_AUTHORIZED');
        }
        if (app()->environment('production') && config('xlwms.de_operator_batch_enabled', false)) {
            app(DeAuthoritativePhysicalStockSyncService::class)->run(true);
        }
        $order = Order::query()->eligibleForOperations()->with([
            'address', 'items', 'shipment.warehouse', 'shipment.carrierService',
            'shipment.overseaReview',
        ])->findOrFail($orderId);
        if ($order->shipment?->warehouse?->code !== 'DE' || ! $this->routeReady($order)) {
            throw new RuntimeException('DE_ORDER_WRONG_WAREHOUSE');
        }
        $fresh = $this->preview->forOrder($order, true, false, 'DE');
        if ($fresh['blockers'] !== []
            || ! hash_equals((string) $fresh['payload_hash'], $expectedFingerprint)
            || (int) ($fresh['payload']['isSubmit'] ?? -1) !== 1
            || ($fresh['payload']['whCode'] ?? null) !== 'Hannover') {
            throw new RuntimeException('DE_LIVE_ORDER_PREFLIGHT_STALE_OR_BLOCKED');
        }
        $payload = (array) $fresh['payload'];
        $connection = WarehouseOpenApiConnection::query()
            ->where('warehouse_id', $order->shipment->warehouse_id)
            ->where('provider', 'xlwms')->firstOrFail();
        if ($connection->oms_warehouse_code !== 'Hannover') {
            throw new RuntimeException('DE_OMS_WAREHOUSE_MISMATCH');
        }

        // Unique order and third-party reference ledger are established BEFORE
        // touching OMS; any existing record blocks a second CREATE forever.
        $attempt = DB::transaction(function () use ($order, $actorId, $fresh) {
            if (XlwmsDispatchAttempt::query()->where('order_id', $order->id)
                ->lockForUpdate()->exists()) {
                throw new RuntimeException('DE_ORDER_ALREADY_HAS_PROVIDER_ATTEMPT');
            }
            return XlwmsDispatchAttempt::query()->create([
                'order_id' => $order->id,
                'warehouse_id' => $order->shipment->warehouse_id,
                'third_order_no' => $fresh['oms_reference'],
                'state' => 'checking_existing',
                'request_fingerprint' => $fresh['payload_hash'],
                'requested_by' => $actorId,
            ]);
        });

        try {
            // Exact reference + product quantity check. An OMS order imported by
            // staff/manual upload is not proof that a second CREATE is safe.
            $existing = $this->oms->findExistingExact(
                $connection,
                (string) $payload['thirdOrderNo'],
                (string) $payload['platformOrderNo'],
                [(string) $payload['referOrderNo'], (string) $order->external_order_number],
                (array) $payload['productList'],
            );
            if ($existing !== []) {
                $attempt->forceFill([
                    'state' => 'blocked_existing_oms_order',
                    'oms_order_no' => (string) ($existing[0]['outboundOrderNo'] ?? '') ?: null,
                    'failure_code' => 'OMS_DUPLICATE_ORDER',
                    'last_checked_at' => now(),
                ])->save();
                return $this->result($attempt, 'skipped', 'OMS_DUPLICATE_ORDER');
            }
            $this->oms->assertCatalogReady(
                $connection,
                'Hannover',
                (string) $payload['logisticsChannel'],
                array_column((array) $payload['productList'], 'sku'),
            );
            if (app()->environment('production') && config('xlwms.de_operator_batch_enabled', false)) {
                app(DeAuthoritativePhysicalStockSyncService::class)->run(true);
                $latestOrder = Order::query()->eligibleForOperations()->findOrFail($orderId);
                $latest = $this->preview->forOrder($latestOrder, true, false, 'DE');
                if (! $this->routeReady($latestOrder) || $latest['blockers'] !== [] || ! hash_equals($expectedFingerprint, (string) $latest['payload_hash'])) {
                    throw new RuntimeException('DE_ORDER_CHANGED_DURING_SCAN');
                }
            }
            // A transient failure after this line is NEVER retried blindly.
            $attempt->forceFill(['state' => 'request_in_flight', 'last_checked_at' => now()])->save();
            $created = $this->oms->createSingle($connection, $payload);
            if (($created['confirmed'] ?? false) !== true
                || ! filled($created['order_no'] ?? null)) {
                throw new RuntimeException('DE_OMS_CREATE_UNCONFIRMED');
            }
            $omsOrderNo = (string) $created['order_no'];
            $attempt->forceFill([
                'state' => 'oms_create_acknowledged',
                'oms_order_no' => $omsOrderNo,
                'last_checked_at' => now(),
            ])->save();

            // OMS ACK alone does not prove provider-accepted order identity.
            $remote = $this->oms->readExactOrderStatus($connection, $omsOrderNo);
            if ($remote === null
                || ($remote['third_order_no'] ?? null) !== $payload['thirdOrderNo']
                || ($remote['platform_order_no'] ?? null) !== $payload['platformOrderNo']) {
                $attempt->forceFill([
                    'state' => 'uncertain_manual_reconcile',
                    'failure_code' => 'DE_OMS_CREATE_READBACK_UNVERIFIED',
                    'last_checked_at' => now(),
                ])->save();
                return $this->result($attempt, 'uncertain', 'DE_OMS_CREATE_READBACK_UNVERIFIED');
            }
            $attempt->forceFill([
                'state' => 'oms_create_confirmed',
                'oms_status_code' => $remote['status_code'],
                'oms_status_checked_at' => now(),
                'oms_tracking_no' => $remote['tracking_no'] ?? null,
                'last_checked_at' => now(),
                'failure_code' => null,
            ])->save();
            // Deliberately do not mark shipment shipped: OMS creation is NOT
            // a provider shipped event or a genuine tracking return.
            return $this->result($attempt, 'created', 'DE_OMS_CREATE_READBACK_CONFIRMED');
        } catch (Throwable $error) {
            $uncertain = in_array($attempt->state, [
                'request_in_flight', 'oms_create_acknowledged',
            ], true);
            $code = $uncertain ? 'DE_OMS_CREATE_RESULT_UNCERTAIN'
                : 'DE_OMS_PREFLIGHT_OR_DUPLICATE_LOOKUP_FAILED';
            $attempt->forceFill([
                'state' => $uncertain ? 'uncertain_manual_reconcile' : 'blocked_preflight_error',
                'failure_code' => $code,
                'last_checked_at' => now(),
            ])->save();
            return $this->result($attempt, $uncertain ? 'uncertain' : 'skipped', $code);
        }
    }

    private function routeReady(Order $order): bool
    {
        return in_array($order->routing_warehouse_code, [null, 'DE'], true)
            && ! in_array((string) $order->workflow_status,
                ['out_of_stock', 'pending_allocation', ...OrderManualHoldStatus::CODES], true);
    }

    /** @return array{status:string,code:string,oms_order_no:?string} */
    private function result(XlwmsDispatchAttempt $attempt, string $status, string $code): array
    {
        return [
            'status' => $status, 'code' => $code,
            'oms_order_no' => $attempt->oms_order_no,
        ];
    }
}
