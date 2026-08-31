<?php

namespace App\Modules\Kol\Infrastructure\AffiliateWp;

use App\Modules\Kol\Application\AffiliateWp\ConnectorResponse;
use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use LogicException;

final class LocalUatAffiliateWpGateway implements AffiliateWpGateway
{
    public function descriptor(): array
    {
        return [
            'configured' => true,
            'url' => 'local-uat-fake://affiliatewp',
            'connection_key' => $this->connectionKey(),
            'key_id' => 'local-uat-fake',
        ];
    }

    public function health(): ConnectorResponse
    {
        return $this->response(['status' => 'ok', 'fixture' => 'local_uat_fake']);
    }

    public function snapshot(string $resource, ?string $cursor = null, ?string $updatedAfter = null): ConnectorResponse
    {
        if ($resource !== 'affiliates') {
            throw new LogicException('Local UAT fake only supports M1.4B Affiliate snapshots.');
        }
        if ($cursor !== null) {
            return $this->response(['items' => [], 'next_cursor' => null]);
        }

        return $this->response([
            'items' => [
                [
                    'affiliate_id' => 810001,
                    'user_id' => 710001,
                    'user_email' => 'uat-external-pending@example.test',
                    'display_name' => 'UAT External Pending',
                    'email' => 'uat-external-pending@example.test',
                    'status' => 'pending',
                ],
                [
                    'affiliate_id' => 810002,
                    'user_id' => 710002,
                    'user_email' => 'uat-external-active@example.test',
                    'display_name' => 'UAT External Active',
                    'email' => 'uat-external-active@example.test',
                    'status' => 'active',
                ],
            ],
            'next_cursor' => null,
        ]);
    }

    public function provisionAffiliate(array $payload, string $idempotencyKey): ConnectorResponse
    {
        $kolId = (int) ($payload['erp_kol_id'] ?? 0);
        $email = (string) ($payload['email'] ?? '');

        if (str_contains($email, 'provider-error')) {
            throw ConnectorException::retryable('Synthetic local UAT provider timeout.', 'PROVIDER_UNAVAILABLE');
        }
        if (str_contains($email, 'conflict')) {
            throw ConnectorException::nonRetryable('Synthetic local UAT Affiliate ID conflict.', 'AFFILIATE_ID_CONFLICT');
        }

        $status = str_contains($email, 'readback') ? 'pending' : 'active';

        return $this->response([
            'affiliate_id' => 820000 + $kolId,
            'user_id' => 720000 + $kolId,
            'user_email' => $email !== '' ? $email : null,
            'status' => $status,
            'idempotency_key_echo' => $idempotencyKey,
        ]);
    }

    public function setAffiliateStatus(int $affiliateId, string $status, string $idempotencyKey): ConnectorResponse
    {
        return $this->response(['affiliate_id' => $affiliateId, 'status' => $status]);
    }

    public function checkCoupon(string $code): ConnectorResponse
    {
        throw new LogicException('Coupon is M1.4C and is unavailable in the M1.4B local UAT fake.');
    }

    public function provisionCoupon(array $payload, string $idempotencyKey): ConnectorResponse
    {
        throw new LogicException('Coupon is M1.4C and is unavailable in the M1.4B local UAT fake.');
    }

    public function completePayout(array $payload, string $idempotencyKey): ConnectorResponse
    {
        throw new LogicException('Payout is outside M1.4B local UAT scope.');
    }

    private function response(mixed $data): ConnectorResponse
    {
        return ConnectorResponse::fromArray([
            'ok' => true,
            'contract_version' => '1',
            'request_id' => 'local-uat-fixture',
            'connection_key' => $this->connectionKey(),
            'data' => $data,
        ]);
    }

    private function connectionKey(): string
    {
        return (string) config('services.andy_core.connection_key', 'beyourlover.com');
    }
}
