<?php

namespace App\Modules\Logistics\Infrastructure\ShipOut;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * R2/R5 bridge foundation. SANDBOX-only and GET-only until real sandbox
 * inbound/outbound acceptance, credential rotation, and Owner Production PASS.
 * No default credential, write method, arbitrary URL, or ERP stock mutation.
 */
final class ShipOutReadOnlyGateway
{
    private const BASE = 'https://opendev.shipout.com/api';
    private const WAREHOUSES = '/open-api/oms/info/warehouse/list';
    private const STOCK = '/open-api/oms/stock/list';

    public function __construct(
        private readonly string $appKey,
        private readonly string $secret,
        private readonly string $bearer,
    ) {}

    /** @return list<array<string,mixed>> */
    public function warehouses(): array
    {
        $response = $this->get(self::WAREHOUSES, []);
        $rows = $response['data'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('SHIPOUT_WAREHOUSE_RESPONSE_INCOMPLETE');
        }

        return $rows;
    }

    /** @return array{records:list<array<string,mixed>>,total:int} */
    public function stockPage(string $clientOrgId, string $warehouseId, int $page = 1, int $size = 50): array
    {
        if (trim($clientOrgId) === '' || trim($warehouseId) === ''
            || $page < 1 || $size < 1 || $size > 100) {
            throw new RuntimeException('SHIPOUT_STOCK_QUERY_INVALID');
        }

        $response = $this->get(self::STOCK, [
            'clientOrgId' => $clientOrgId, 'warehouseId' => $warehouseId,
            'curPageNo' => $page, 'pageSize' => $size,
        ]);
        $data = $response['data'] ?? null;
        if (! is_array($data) || ! isset($data['records'], $data['total'])
            || ! is_array($data['records']) || ! array_is_list($data['records'])
            || ! (is_int($data['total']) && $data['total'] >= 0
                || is_string($data['total']) && preg_match('/^(0|[1-9][0-9]*)$/', $data['total']) === 1)) {
            throw new RuntimeException('SHIPOUT_STOCK_RESPONSE_INCOMPLETE');
        }

        $total = (int) $data['total'];
        if (count($data['records']) > $size || count($data['records']) > $total
            || ($total > 0 && $page <= (int) ceil($total / $size) && $data['records'] === [])) {
            throw new RuntimeException('SHIPOUT_STOCK_PAGE_INCOMPLETE');
        }
        $records = [];
        $seen = [];
        foreach ($data['records'] as $row) {
            if (! is_array($row) || trim((string) ($row['omsSku'] ?? '')) === ''
                || (string) ($row['warehouseId'] ?? '') !== $warehouseId
                || ! isset($row['availableQuantity']) || ! is_int($row['availableQuantity'])
                || $row['availableQuantity'] < 0) {
                throw new RuntimeException('SHIPOUT_STOCK_ROW_UNTRUSTED');
            }
            $sku = trim((string) $row['omsSku']);
            if (isset($seen[$sku])) {
                throw new RuntimeException('SHIPOUT_STOCK_DUPLICATE_SKU');
            }
            $seen[$sku] = true;
            $records[] = [
                'warehouseId' => $warehouseId,
                'omsSku' => $sku,
                'availableQuantity' => $row['availableQuantity'],
                // Do not treat standardQuantity as available.
                'standardQuantity' => $row['standardQuantity'] ?? null,
                'outboundingQuantity' => $row['outboundingQuantity'] ?? null,
            ];
        }

        return ['records' => $records, 'total' => $total];
    }

    /** @return array<string,mixed> */
    private function get(string $path, array $query): array
    {
        if (! (bool) config('shipout.sandbox_read_enabled', false)) {
            throw new RuntimeException('SHIPOUT_SANDBOX_READ_DISABLED');
        }
        if (! in_array($path, [self::WAREHOUSES, self::STOCK], true)) {
            throw new RuntimeException('SHIPOUT_PATH_NOT_ALLOWED');
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
            $response = Http::acceptJson()
                ->withOptions(['allow_redirects' => false, 'verify' => true])
                ->withHeaders([
                    'Authorization' => 'bearer '.$token,
                    'appKey' => $appKey,
                    'timestamp' => $timestamp,
                    'version' => '1.0.0',
                    'sign' => ShipOutSignature::sign($path, $timestamp, $secret),
                ])
                ->connectTimeout(5)->timeout(15)
                ->get(self::BASE.$path, $query);
        } catch (\Throwable) {
            throw new RuntimeException('SHIPOUT_TRANSPORT_UNCERTAIN');
        }
        if ($response->status() !== 200) {
            throw new RuntimeException('SHIPOUT_HTTP_REJECTED');
        }

        $json = $response->json();
        if (! is_array($json) || ($json['result'] ?? null) !== 'OK') {
            throw new RuntimeException('SHIPOUT_BUSINESS_REJECTED');
        }

        return $json;
    }
}
