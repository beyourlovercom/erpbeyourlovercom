<?php

namespace App\Modules\Logistics\Application\ShipOut;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * R10-B pure permission gate only. Never sends HTTP or writes a DB row.
 * It cannot authorize dispatch by itself; a separate one-shot dispatcher,
 * independent order preflight, and explicit Owner exact-order approval are required.
 */
final class ShipOutProductionCanaryGate
{
    public function assertExecutionAuthorized(
        int $erpOrderId,
        string $providerOrderNo,
        string $providerWarehouseId,
        string $expectedPayloadHash,
        string $currentPayloadHash,
        string $typedConfirmation,
    ): void {
        if (! app()->environment('production')) {
            throw new RuntimeException('SHIPOUT_R10_PRODUCTION_ONLY');
        }

        foreach ([
            'production_read_enabled',
            'production_dispatch_enabled',
            'production_canary_execute_enabled',
            'production_canary_owner_approved',
        ] as $flag) {
            if (! (bool) config('shipout.'.$flag, false)) {
                throw new RuntimeException('SHIPOUT_R10_CANARY_DISABLED');
            }
        }

        if (trim((string) config('shipout.production_warehouse_id', '')) !== '1265864414750314497'
            || trim((string) config('shipout.production_client_org_id', '')) !== '57893'
            || $providerWarehouseId !== '1265864414750314497') {
            throw new RuntimeException('SHIPOUT_R10_IDENTITY_DENIED');
        }

        $allowedId = trim((string) config('shipout.production_canary_order_id', ''));
        $allowedNo = trim((string) config('shipout.production_canary_order_no', ''));
        if ($erpOrderId < 1 || $allowedId === '' || $allowedId !== (string) $erpOrderId
            || $allowedNo === '' || $providerOrderNo === ''
            || strlen($providerOrderNo) > 60 || ! hash_equals($allowedNo, $providerOrderNo)) {
            throw new RuntimeException('SHIPOUT_R10_ORDER_NOT_ALLOWLISTED');
        }

        if (! preg_match('/^[a-f0-9]{64}$/D', $expectedPayloadHash)
            || ! hash_equals($expectedPayloadHash, $currentPayloadHash)) {
            throw new RuntimeException('SHIPOUT_R10_PREFLIGHT_STALE');
        }
        if (! hash_equals('SUBMIT_SHIPOUT_ORDER_'.$erpOrderId, $typedConfirmation)) {
            throw new RuntimeException('SHIPOUT_R10_CONFIRMATION_MISMATCH');
        }

        $expiresText = trim((string) config('shipout.production_canary_expires_at_utc', ''));
        $expires = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $expiresText, new DateTimeZone('UTC'));
        $now = now('UTC')->getTimestamp();
        // The owner-given one-time approval cannot remain live longer than 1 hour.
        if (! $expires || $expires->format('Y-m-d\TH:i:s\Z') !== $expiresText
            || $expires->getTimestamp() <= $now || $expires->getTimestamp() > $now + 3600) {
            throw new RuntimeException('SHIPOUT_R10_APPROVAL_EXPIRED_OR_INVALID');
        }

        foreach ([
            'production_app_key', 'production_app_secret', 'production_bearer',
        ] as $credential) {
            if (trim((string) config('shipout.'.$credential, '')) === '') {
                throw new RuntimeException('SHIPOUT_R10_CREDENTIALS_MISSING');
            }
        }
    }
}
