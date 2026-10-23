<?php

namespace App\Modules\Finance\Application\Contracts;

/** Finance-owned port; identifiers keep Eloquent models outside the Application layer. */
interface B2BTradeCashGateway
{
    /** @param array<string,mixed> $attestation */
    public function confirmReceipt(int $tradeOrderId,int $evidenceId,array $attestation,int $financeUserId): int;

    /** @param array<string,mixed> $attestation */
    public function confirmRefund(int $tradeOrderId,int $originalEventId,array $attestation,int $financeUserId): int;

    /** @return array<string,mixed> */
    public function summary(int $tradeOrderId): array;
}
