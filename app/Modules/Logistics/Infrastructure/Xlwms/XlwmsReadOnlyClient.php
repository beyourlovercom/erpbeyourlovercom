<?php

namespace App\Modules\Logistics\Infrastructure\Xlwms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Explicit allowlist: three read-only OMS v1 endpoints. No generic request()
 * method is exposed and /outboundOrder/create is NOT available in P0-P2.
 */
final class XlwmsReadOnlyClient
{
    private const WAREHOUSES = 'warehouse/options';

    private const CHANNELS = 'logistics/channel/list';

    private const PRODUCTS = 'product/pagelist';

    public function isConfigured(): bool
    {
        return (bool) config('xlwms.enabled', false)
            && trim((string) config('xlwms.app_key', '')) !== ''
            && trim((string) config('xlwms.app_secret', '')) !== '';
    }

    /** @return array{http_status:int,body:array<string,mixed>|list<mixed>} */
    public function warehouses(): array
    {
        return $this->read(self::WAREHOUSES, null);
    }

    /** @param array<string,mixed> $filters
     *  @return array{http_status:int,body:array<string,mixed>|list<mixed>}
     */
    public function channels(array $filters = []): array
    {
        return $this->read(self::CHANNELS, (object) $filters);
    }

    /** @param array<string,mixed> $filters
     *  @return array{http_status:int,body:array<string,mixed>|list<mixed>}
     */
    public function products(array $filters = []): array
    {
        return $this->read(self::PRODUCTS, (object) $filters);
    }

    /** @return array{http_status:int,body:array<string,mixed>|list<mixed>} */
    private function read(string $endpoint, ?object $data): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('XLWMS read-only integration is disabled or lacks credentials.');
        }

        $baseUrl = rtrim((string) config('xlwms.base_url'), '/');
        $parts = parse_url($baseUrl);
        // Prevent misconfigured credentials being sent to an untrusted host.
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== 'api.xlwms.com'
            || ($parts['path'] ?? null) !== '/openapi/v1'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
            || isset($parts['fragment']) || isset($parts['port'])) {
            throw new RuntimeException('XLWMS base URL is not the approved OpenAPI v1 endpoint.');
        }

        $appKey = (string) config('xlwms.app_key');
        $reqTime = (string) time();
        $normalized = $data === null ? null : XlwmsSignature::normalize($data);
        $signature = XlwmsSignature::sign(
            $appKey, (string) config('xlwms.app_secret'), $reqTime, $normalized
        );
        $payload = ['appKey' => $appKey, 'reqTime' => $reqTime];
        if ($normalized !== null) {
            $payload['data'] = $normalized;
        }

        // Do not call ->throw(): its exception can contain the authcode URL.
        // Redirects are off; avoid leaking the signed request to another host.
        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withOptions(['allow_redirects' => false, 'verify' => config('xlwms.ca_bundle') ?: true])
                ->connectTimeout(5)
                ->timeout((int) config('xlwms.timeout_seconds', 15))
                ->post($baseUrl.'/'.$endpoint.'?authcode='.$signature, $payload);
        } catch (\Throwable) {
            // Signed URL can be embedded in transport exceptions; never
            // chain the original exception into logs or user-facing output.
            throw new RuntimeException('XLWMS read-only transport failed.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('XLWMS read-only request failed with HTTP '.$response->status().'.');
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('XLWMS read-only response did not contain a JSON structure.');
        }

        // Do not label a 200 response "connected": business-level error
        // codes in response body must be checked against live OMS contract.
        return ['http_status' => $response->status(), 'body' => $json];
    }
}
