<?php

namespace App\Modules\Logistics\Infrastructure\Xlwms;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Manual one-shot OMS credential probe. No credential persistence, dispatch,
 * warehouse mutation, queueing, or request/response logging.
 */
final class XlwmsCredentialProbe
{
    /** @return array{status:string,http_status:int|null,code:string|null,warehouse_count:int|null} */
    public function verify(string $appKey, string $appSecret): array
    {
        if (! (bool) config('xlwms.manual_probe_enabled', false)) {
            return $this->result('disabled');
        }

        // Fixed official OMS hostname: never accept a user-provided URL or redirect.
        $url = 'https://api.xlwms.com/openapi/v1/warehouse/options';
        $reqTime = (string) time();
        $signature = XlwmsSignature::sign($appKey, $appSecret, $reqTime);
        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(5)
                ->timeout(15)
                ->post($url.'?authcode='.$signature, [
                    'appKey' => $appKey,
                    'reqTime' => $reqTime,
                ]);
        } catch (Throwable) {
            // Transport exception may include full signed query URL.
            return $this->result('network_error');
        }

        $httpStatus = $response->status();
        if ($httpStatus === 401 || $httpStatus === 403) {
            return $this->result('rejected', $httpStatus);
        }
        if ($httpStatus !== 200) {
            return $this->result('http_error', $httpStatus);
        }

        $body = $response->json();
        if (! is_array($body)) {
            return $this->result('unexpected_response', $httpStatus);
        }

        $code = $body['code'] ?? null;
        $safeCode = (is_string($code) || is_int($code)) && preg_match('/^[a-zA-Z0-9_\-]{1,36}$/', (string) $code)
            ? (string) $code : null;

        $successFlag = $body['success'] ?? null;
        $knownSuccessCode = $safeCode !== null && in_array(strtoupper($safeCode), ['0', '200', 'SUCCESS'], true);
        $knownFailureCode = $safeCode !== null && ! $knownSuccessCode;
        $dataPresent = array_key_exists('data', $body);

        if ($successFlag === false || $knownFailureCode) {
            return $this->result('business_rejected', $httpStatus, $safeCode);
        }

        if (! (($successFlag === true || $knownSuccessCode) && $dataPresent)) {
            // HTTP 200 by itself cannot prove that vendor accepted credentials.
            return $this->result('unconfirmed', $httpStatus, $safeCode);
        }

        $data = $body['data'];
        $records = is_array($data) && array_is_list($data) ? $data : null;
        if (is_array($data) && is_array($data['records'] ?? null)) {
            $records = $data['records'];
        }
        if (is_array($data) && is_array($data['list'] ?? null)) {
            $records = $data['list'];
        }

        return $this->result('verified', $httpStatus, $safeCode, $records === null ? null : count($records));
    }

    /** @return array{status:string,http_status:int|null,code:string|null,warehouse_count:int|null} */
    private function result(
        string $status, ?int $httpStatus = null, ?string $code = null, ?int $warehouseCount = null
    ): array {
        return [
            'status' => $status,
            'http_status' => $httpStatus,
            'code' => $code,
            'warehouse_count' => $warehouseCount,
        ];
    }
}
