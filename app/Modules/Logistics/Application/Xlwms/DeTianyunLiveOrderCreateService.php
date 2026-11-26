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
    public function create(int $orderId, string $expectedFingerprint, int $actorId, ?int $recoveryAttemptId = null): array
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

        // Retain the unique attempt ledger forever. A separately claimed and
        // audited recovery may reopen ONLY a proven pre-CREATE failure.
        $attempt = DB::transaction(function () use ($order, $actorId, $fresh, $recoveryAttemptId) {
            $prior = XlwmsDispatchAttempt::query()->where('order_id', $order->id)->lockForUpdate()->first();
            if ($recoveryAttemptId !== null) {
                if (! $prior || ! DeTianyunPreflightRecovery::eligible($prior, $order, $recoveryAttemptId)
                    || ! DeTianyunPreflightRecovery::claimed((int) $order->id, $actorId, $fresh['payload_hash'], $recoveryAttemptId)) {
                    throw new RuntimeException('DE_PREFLIGHT_RECOVERY_NOT_SAFE');
                }
                $actor = \App\Models\User::query()->findOrFail($actorId);
                if (! $actor->is_active || ! app(\App\Modules\Logistics\Application\LogisticsExecutionAccess::class)->allows($actor)) {
                    throw new RuntimeException('DE_PREFLIGHT_RECOVERY_NOT_AUTHORIZED');
                }
                app(\App\Modules\Audit\Application\Contracts\AuditRecorder::class)->record(
                    \App\Modules\Audit\Application\AuditActor::user($actor, \App\Modules\Audit\Application\AuditSource::InternalSystem),
                    'logistics.de_preflight_recovery', XlwmsDispatchAttempt::class, $prior->id,
                    before: ['state' => $prior->state, 'failure_code' => $prior->failure_code, 'request_fingerprint' => $prior->request_fingerprint, 'requested_by' => $prior->requested_by],
                    after: ['state' => 'checking_existing', 'request_fingerprint' => $fresh['payload_hash'], 'requested_by' => $actorId],
                    reason: 'explicit_pre_create_only_recovery');
                $prior->forceFill(['state' => 'checking_existing', 'request_fingerprint' => $fresh['payload_hash'],
                    'requested_by' => $actorId, 'failure_code' => null, 'last_checked_at' => now()])->save();
                return $prior;
            }
            if ($prior) throw new RuntimeException('DE_ORDER_ALREADY_HAS_PROVIDER_ATTEMPT');
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
            $stage = $attempt->state;
            $attempt->forceFill([
                'state' => $uncertain ? 'uncertain_manual_reconcile' : 'blocked_preflight_error',
                'failure_code' => $code,
                'last_checked_at' => now(),
            ])->save();
            // Only allowlisted machine codes, no provider payload or credentials.
            $reason = explode(':', $error->getMessage(), 2)[0];
            if (! in_array($reason, [
                'OMS_TRANSPORT_UNCERTAIN', 'OMS_SERVER_UNCERTAIN', 'OMS_HTTP_REJECTED', 'OMS_BUSINESS_REJECTED',
                'OMS_DUPLICATE_LOOKUP_INPUT_INVALID', 'OMS_DETAIL_RESPONSE_INVALID', 'OMS_PAGE_RESPONSE_INVALID',
                'OMS_DUPLICATE_SCAN_INCOMPLETE', 'OMS_STATUS_IDENTITY_MISMATCH', 'OMS_DUPLICATE_AMBIGUOUS',
                'OMS_ITEM_QUANTITY_INVALID', 'OMS_CATALOG_INPUT_INVALID', 'OMS_CHANNEL_NOT_AVAILABLE',
                'OMS_PRODUCT_CATALOG_UNAVAILABLE', 'OMS_SKU_NOT_APPROVED_OR_MISSING', 'DE_ORDER_CHANGED_DURING_SCAN',
                'DE_PHYSICAL_SYNC_ALREADY_RUNNING', 'DE_PHYSICAL_SNAPSHOT_NOT_FRESH_OR_WRONG_WAREHOUSE',
                'DE_PHYSICAL_PROVIDER_NOT_READY', 'DE_PHYSICAL_INCOMPLETE_PROVIDER_COVERAGE',
                'DE_PHYSICAL_INVALID_SOURCE_STOCK', 'DE_OMS_CREATE_UNCONFIRMED', 'OMS_CREATE_NOT_CONFIRMED',
                'OMS_STATUS_RESPONSE_INVALID', 'OMS_STATUS_QUERY_INVALID', 'XLWMS_OPENAPI_CREATE_BLOCKED_USE_OMS_DRAFT_IMPORT',
            ], true)) $reason = 'DE_UNCLASSIFIED_GUARD_FAILURE';
            try {
                logger()->warning('DE_OMS_OUTBOUND_GUARD_FAILURE', ['order_id' => $orderId,
                    'attempt_id' => $attempt->id, 'stage' => $stage, 'reason' => $reason]);
            } catch (Throwable) {
                // Durable failure state already saved; log transport cannot change it.
            }
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
