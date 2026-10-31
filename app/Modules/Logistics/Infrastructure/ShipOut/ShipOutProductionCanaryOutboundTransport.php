<?php

namespace App\Modules\Logistics\Infrastructure\ShipOut;

use App\Modules\Logistics\Application\ShipOut\ShipOutProductionCanaryGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A single hard-scoped Production outbound. Not bound to a route or scheduler.
 * Transitions an already persisted attempt to sent_once BEFORE network I/O,
 * preventing any future automatic retry even when the response is ambiguous.
 */
final class ShipOutProductionCanaryOutboundTransport
{
    private const BASE = 'https://open.shipout.com/api';

    private const PATH = '/open-api/oms/order/batchSubmitAsync';

    public function __construct(private readonly ShipOutProductionCanaryGate $gate) {}

    /** @param array<int,array<string,mixed>> $payload
     * @return array{status:string,provider_order_id:?string,result_code:string}
     */
    public function submitOne(
        int $orderId,
        array $payload,
        string $expectedPayloadHash,
        string $typedConfirmation,
    ): array {
        $row = $payload[0] ?? null;
        if (count($payload) !== 1 || ! is_array($row)
            || (int) ($row['sid'] ?? -1) !== 1
            || (string) ($row['warehouseId'] ?? '') !== '1265864414750314497'
            || ! is_array($row['shipmentForms'] ?? null)
            || count($row['shipmentForms']) !== 1
            || (string) ($row['shipmentForms'][0]['outboundInfo']['sysServiceId'] ?? '') !== '9'
            || (int) ($row['shipmentForms'][0]['shippingInfo']['carrierId'] ?? 0) !== 9
            || isset($row['shipmentForms'][0]['outboundInfo']['serviceGroupId'])
            || isset($row['shipmentForms'][0]['outboundInfo']['shipmentOutboundLabel'])
            || ! is_array($row['shipmentForms'][0]['productList'] ?? null)
            || count($row['shipmentForms'][0]['productList']) < 1) {
            throw new RuntimeException('SHIPOUT_R10_TRANSPORT_PAYLOAD_BLOCKED');
        }
        foreach ($row['shipmentForms'][0]['productList'] as $item) {
            if (! is_array($item)
                || trim((string) ($item['omsSku'] ?? '')) === ''
                || ! is_int($item['qty'] ?? null) || $item['qty'] < 1) {
                throw new RuntimeException('SHIPOUT_R10_TRANSPORT_ITEM_INVALID');
            }
        }

        $ref = trim((string) ($row['orderNo'] ?? ''));
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->gate->assertExecutionAuthorized(
            $orderId, $ref, (string) $row['warehouseId'],
            $expectedPayloadHash, $hash, $typedConfirmation,
        );

        // Atomic one-time state transition. No additional call is allowed
        // after this succeeds; even a process crash creates a manual hold.
        try {
            $claimed = DB::table('shipout_dispatch_attempts')
                ->where('order_id', $orderId)
                ->where('order_no', $ref)
                ->where('provider_warehouse_id', '1265864414750314497')
                ->where('payload_hash', $hash)
                ->where('status', 'in_flight')
                ->update(['status' => 'sent_once', 'updated_at' => now()]);
        } catch (\Throwable) {
            throw new RuntimeException('SHIPOUT_R10_ATTEMPT_LEDGER_UNAVAILABLE');
        }
        if ($claimed !== 1) {
            throw new RuntimeException('SHIPOUT_R10_POST_ALREADY_CLAIMED');
        }

        $appKey = trim((string) config('shipout.production_app_key', ''));
        $secret = trim((string) config('shipout.production_app_secret', ''));
        $bearer = trim((string) config('shipout.production_bearer', ''));
        if (str_starts_with(strtolower($bearer), 'bearer ')) {
            $bearer = trim(substr($bearer, 7));
        }
        $timestamp = (string) now()->getTimestampMs();
        try {
            $response = Http::acceptJson()->asJson()
                ->withOptions(['allow_redirects' => false, 'verify' => true])
                ->withHeaders([
                    'Authorization' => 'bearer '.$bearer,
                    'appKey' => $appKey,
                    'timestamp' => $timestamp,
                    'version' => '1.0.0',
                    'sign' => ShipOutSignature::sign(self::PATH, $timestamp, $secret),
                ])
                ->connectTimeout(5)->timeout(15)
                ->post(self::BASE.self::PATH, $payload);
        } catch (\Throwable) {
            // The provider may have accepted the request despite a timeout.
            throw new RuntimeException('SHIPOUT_R10_SUBMISSION_OUTCOME_UNKNOWN');
        }

        if ($response->status() !== 200) {
            throw new RuntimeException('SHIPOUT_R10_SUBMISSION_OUTCOME_UNKNOWN');
        }
        $json = $response->json();
        if (is_array($json) && ! array_is_list($json)
            && in_array($json['result'] ?? null, ['ERR', 'ERROR'], true)) {
            return [
                'status' => 'rejected',
                'provider_order_id' => null,
                'result_code' => 'PROVIDER_VALIDATION_REJECTED',
            ];
        }
        if (! is_array($json) || ! array_is_list($json)
            || count($json) !== 1 || ! is_array($json[0])) {
            throw new RuntimeException('SHIPOUT_R10_SUBMISSION_OUTCOME_UNKNOWN');
        }
        $answer = $json[0];
        if (($answer['result'] ?? null) !== 'OK') {
            return [
                'status' => 'rejected',
                'provider_order_id' => null,
                'result_code' => 'PROVIDER_BUSINESS_REJECTED',
            ];
        }
        $data = $answer['data'] ?? null;
        $providerId = is_array($data) ? trim((string) ($data['orderId'] ?? '')) : '';
        if (! is_array($data) || $providerId === ''
            || (string) ($data['orderNO'] ?? '') !== $ref
            || (int) ($data['sid'] ?? -1) !== 1) {
            throw new RuntimeException('SHIPOUT_R10_SUBMISSION_OUTCOME_UNKNOWN');
        }

        // Async ACK: warehouse fulfillment/tracking remains unverified.
        return [
            'status' => 'acknowledged',
            'provider_order_id' => $providerId,
            'result_code' => 'ASYNC_ACCEPTED_QUERY_REQUIRED',
        ];
    }
}
