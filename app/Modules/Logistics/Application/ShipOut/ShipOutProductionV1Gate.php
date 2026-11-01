<?php

namespace App\Modules\Logistics\Application\ShipOut;

use RuntimeException;

/**
 * Ordinary approved US-LA orders: distinct default-OFF V1 launch gates,
 * exact fingerprint and typed per-order confirmation. Caller authentication
 * and logistics execution permission are enforced by web route middleware.
 */
final class ShipOutProductionV1Gate
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
            throw new RuntimeException('SHIPOUT_V1_PRODUCTION_ONLY');
        }
        foreach ([
            'production_read_enabled',
            'production_dispatch_enabled',
            'production_v1_read_enabled',
            'production_v1_dispatch_enabled',
            'production_v1_owner_approved',
        ] as $flag) {
            if (! (bool) config('shipout.'.$flag, false)) {
                throw new RuntimeException('SHIPOUT_V1_DISPATCH_DISABLED');
            }
        }
        if ($erpOrderId < 1 || $providerOrderNo === '' || strlen($providerOrderNo) > 60
            || $providerWarehouseId !== '1265864414750314497'
            || (string) config('shipout.production_warehouse_id') !== $providerWarehouseId
            || (string) config('shipout.production_client_org_id') !== '57893') {
            throw new RuntimeException('SHIPOUT_V1_IDENTITY_DENIED');
        }
        if (! preg_match('/^[a-f0-9]{64}$/D', $expectedPayloadHash)
            || ! hash_equals($expectedPayloadHash, $currentPayloadHash)) {
            throw new RuntimeException('SHIPOUT_R10_PREFLIGHT_STALE');
        }
        if (! hash_equals('SUBMIT_SHIPOUT_ORDER_'.$erpOrderId, $typedConfirmation)) {
            throw new RuntimeException('SHIPOUT_V1_CONFIRMATION_MISMATCH');
        }
        foreach (['production_app_key', 'production_app_secret', 'production_bearer'] as $key) {
            if (trim((string) config('shipout.'.$key, '')) === '') {
                throw new RuntimeException('SHIPOUT_V1_CREDENTIALS_MISSING');
            }
        }
    }
}
