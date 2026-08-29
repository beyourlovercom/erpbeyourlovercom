<?php

namespace App\Modules\Kol\Application\Contracts;

use App\Modules\Kol\Infrastructure\AffiliateWp\ConnectorResponse;

interface AffiliateWpGateway
{
    public function descriptor(): array;

    public function health(): ConnectorResponse;

    public function snapshot(string $resource, ?string $cursor = null, ?string $updatedAfter = null): ConnectorResponse;

    public function provisionAffiliate(array $payload, string $idempotencyKey): ConnectorResponse;

    public function setAffiliateStatus(int $affiliateId, string $status, string $idempotencyKey): ConnectorResponse;

    public function checkCoupon(string $code): ConnectorResponse;

    public function provisionCoupon(array $payload, string $idempotencyKey): ConnectorResponse;

    public function completePayout(array $payload, string $idempotencyKey): ConnectorResponse;
}
