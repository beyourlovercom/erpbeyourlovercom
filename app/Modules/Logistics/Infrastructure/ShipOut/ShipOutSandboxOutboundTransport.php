<?php

namespace App\Modules\Logistics\Infrastructure\ShipOut;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fixed ShipOut sandbox-only outbound endpoint.
 * A single ERP-backed invocation is made by ShipOutSandboxDispatchService.
 * Never accepts arbitrary endpoints or provides implicit retry.
 */
final class ShipOutSandboxOutboundTransport
{
    private const BASE = 'https://opendev.shipout.com/api';
    private const PATH = '/open-api/oms/order/batchSubmitAsync';

    public function __construct(
        private readonly string $appKey,
        private readonly string $secret,
        private readonly string $bearer,
    ) {}

    /** @param array<int,array<string,mixed>> $payload
     * @return array{status:string,provider_order_id:?string,result_code:string}
     */
    public function submitOne(array $payload): array
    {
        if (! (bool) config('shipout.sandbox_dispatch_enabled', false)) {
            throw new RuntimeException('SHIPOUT_SANDBOX_DISPATCH_DISABLED');
        }
        if (count($payload) !== 1 || ! is_array($payload[0] ?? null)
            || (int) ($payload[0]['sid'] ?? 0) !== 1
            || trim((string) ($payload[0]['orderNo'] ?? '')) === ''
            || trim((string) ($payload[0]['orderNo'] ?? '')) !== trim((string) config('shipout.sandbox_order_allowlist', ''))
            || (string) ($payload[0]['warehouseId'] ?? '') !== trim((string) config('shipout.sandbox_warehouse_id', ''))
            || trim((string) config('shipout.sandbox_warehouse_id', '')) === ''
            || count($payload[0]['shipmentForms'] ?? []) !== 1
            || (string) ($payload[0]['shipmentForms'][0]['outboundInfo']['sysServiceId'] ?? '') !== '9'
            || (int) ($payload[0]['shipmentForms'][0]['shippingInfo']['carrierId'] ?? 0) !== 9) {
            throw new RuntimeException('SHIPOUT_SANDBOX_PAYLOAD_NOT_ALLOWLISTED');
        }
        $appKey = trim($this->appKey);
        $secret = trim($this->secret);
        $token = trim($this->bearer);
        if (str_starts_with(strtolower($token), 'bearer ')) {
            $token = trim(substr($token, 7));
        }
        if ($appKey === '' || $secret === '' || $token === '') {
            throw new RuntimeException('SHIPOUT_CREDENTIAL_MISSING');
        }
        $timestamp = (string) now()->getTimestampMs();
        try {
            $response = Http::acceptJson()->asJson()
                ->withOptions(['allow_redirects' => false, 'verify' => true])
                ->withHeaders([
                    'Authorization' => 'bearer '.$token,
                    'appKey' => $appKey,
                    'timestamp' => $timestamp,
                    'version' => '1.0.0',
                    'sign' => ShipOutSignature::sign(self::PATH, $timestamp, $secret),
                ])
                ->connectTimeout(5)->timeout(15)->post(self::BASE.self::PATH, $payload);
        } catch (\Throwable) {
            // A timeout might mean provider created an order. NEVER retry automatically.
            throw new RuntimeException('SHIPOUT_SUBMISSION_OUTCOME_UNKNOWN');
        }
        if ($response->status() !== 200) {
            throw new RuntimeException('SHIPOUT_SUBMISSION_OUTCOME_UNKNOWN');
        }
        $json = $response->json();
        // Official sandbox can return a top-level {result: ERR} validation
        // envelope with HTTP 200 (confirmed with a deliberately invalid body).
        // This is NOT an async acknowledgement, and no raw error text/PII is logged.
        if (is_array($json) && ! array_is_list($json)
            && in_array($json['result'] ?? null, ['ERR', 'ERROR'], true)) {
            return [
                'status' => 'rejected',
                'provider_order_id' => null,
                'result_code' => 'PROVIDER_TOP_LEVEL_VALIDATION_REJECTED',
            ];
        }
        if (! is_array($json) || ! array_is_list($json) || count($json) !== 1
            || ! is_array($json[0])) {
            throw new RuntimeException('SHIPOUT_SUBMISSION_OUTCOME_UNKNOWN');
        }
        $row = $json[0];
        if (($row['result'] ?? null) !== 'OK') {
            return ['status' => 'rejected', 'provider_order_id' => null, 'result_code' => 'PROVIDER_BUSINESS_REJECTED'];
        }
        $data = $row['data'] ?? null;
        if (! is_array($data)
            || (string) ($data['orderNO'] ?? '') !== (string) $payload[0]['orderNo']
            || (int) ($data['sid'] ?? -1) !== 1) {
            throw new RuntimeException('SHIPOUT_SUBMISSION_OUTCOME_UNKNOWN');
        }
        return [
            // Async ACK ≠ outbound created, tracking available or shipment completed.
            'status' => 'acknowledged',
            'provider_order_id' => trim((string) ($data['orderId'] ?? '')) ?: null,
            'result_code' => 'ASYNC_ACCEPTED_QUERY_REQUIRED',
        ];
    }
}
