<?php

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Application\Contracts\B2BTradeCashGateway;

/** Finance-only business entrypoint: infrastructure detail remains behind a typed port. */
final class B2BTradeCashAccountingService
{
    public function __construct(private readonly B2BTradeCashGateway $gateway) {}

    /** @param array<string,mixed> $attestation */
    public function confirmReceipt(int $tradeOrderId, int $evidenceId, array $attestation, int $financeUserId): int
    {
        return $this->gateway->confirmReceipt($tradeOrderId,$evidenceId,$attestation,$financeUserId);
    }

    /** @param array<string,mixed> $attestation */
    public function confirmRefund(int $tradeOrderId, int $originalEventId, array $attestation, int $financeUserId): int
    {
        return $this->gateway->confirmRefund($tradeOrderId,$originalEventId,$attestation,$financeUserId);
    }

    /** @return array<string,mixed> */
    public function summary(int $tradeOrderId): array
    {
        return $this->gateway->summary($tradeOrderId);
    }
}
