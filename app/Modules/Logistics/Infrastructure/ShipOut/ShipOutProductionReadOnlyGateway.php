<?php

namespace App\Modules\Logistics\Infrastructure\ShipOut;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * R9 production GET-only transport. No routes, no POST/PUT, no dynamic URLs.
 * Completely separate from sandbox ShipOutReadOnlyGateway.
 */
final class ShipOutProductionReadOnlyGateway
{
    private const BASE = 'https://open.shipout.com/api';

    private const WAREHOUSES = '/open-api/oms/info/warehouse/list';

    private const PRODUCTS = '/open-api/oms/product/queryList';

    private const STOCK = '/open-api/oms/stock/list';

    private const ORDER_LIST = '/open-api/oms/order/queryListV2';

    /** @return list<array{warehouseId:string,warehouseName:string}> */
    public function warehouses(): array
    {
        $data = $this->get(self::WAREHOUSES, [])['data'] ?? null;
        if (! is_array($data) || ! array_is_list($data)) {
            throw new RuntimeException('SHIPOUT_PROD_WAREHOUSE_UNTRUSTED');
        }
        $seen = [];
        $result = [];
        foreach ($data as $row) {
            $id = is_array($row) ? trim((string) ($row['warehouseId'] ?? '')) : '';
            if ($id === '' || isset($seen[$id])) {
                throw new RuntimeException('SHIPOUT_PROD_WAREHOUSE_UNTRUSTED');
            }
            $seen[$id] = true;
            $result[] = ['warehouseId' => $id, 'warehouseName' => (string) ($row['warehouseName'] ?? '')];
        }

        return $result;
    }

    /** @return array{records:list<array<string,mixed>>,total:int} */
    public function products(int $page = 1, int $size = 100): array
    {
        $data = $this->page(self::PRODUCTS, ['curPageNo' => $page, 'pageSize' => $size], $page, $size);
        $seen = [];
        $rows = [];
        foreach ($data['records'] as $row) {
            $sku = is_array($row) ? trim((string) ($row['omsSku'] ?? '')) : '';
            if ($sku === '' || isset($seen[$sku])) {
                throw new RuntimeException('SHIPOUT_PROD_PRODUCT_UNTRUSTED');
            }
            $seen[$sku] = true;
            $rows[] = [
                'omsSku' => $sku,
                'skuId' => (string) ($row['skuId'] ?? ''),
                'status' => $row['status'] ?? null,
                'warehouseIds' => $row['warehouseIds'] ?? [],
            ];
        }

        return ['records' => $rows, 'total' => $data['total']];
    }

    /**
     * R10-B duplicate detection only. Never returns recipient or address data.
     * All nonmatching or malformed order rows fail closed.
     *
     * @return array{records:list<array{orderNO:string,orderId:string,status:int|null}>,total:int}
     */
    public function orderPage(string $warehouseId, string $orderNo, int $page = 1, int $size = 10): array
    {
        $configured = trim((string) config('shipout.production_warehouse_id', ''));
        $organization = trim((string) config('shipout.production_client_org_id', ''));
        if ($configured === '' || $organization === '' || $warehouseId !== $configured
            || preg_match('/^[A-Za-z0-9._\-]{1,60}$/D', $orderNo) !== 1) {
            throw new RuntimeException('SHIPOUT_PROD_ORDER_IDENTITY_UNBOUND');
        }
        $data = $this->page(self::ORDER_LIST, [
            'warehouseId' => $warehouseId, 'orderNO' => $orderNo,
            'version' => 'v2', 'curPageNo' => $page, 'pageSize' => $size,
        ], $page, $size);
        $rows = [];
        $seen = [];
        foreach ($data['records'] as $record) {
            $rowOrderNo = is_array($record) ? trim((string) ($record['orderNO'] ?? '')) : '';
            $orderId = is_array($record) ? trim((string) ($record['orderId'] ?? '')) : '';
            if ($rowOrderNo !== $orderNo || $orderId === '' || isset($seen[$orderId])
                || (isset($record['warehouseId']) && (string) $record['warehouseId'] !== $warehouseId)) {
                throw new RuntimeException('SHIPOUT_PROD_ORDER_ROW_UNTRUSTED');
            }
            $seen[$orderId] = true;
            $status = $record['status'] ?? null;
            $rows[] = [
                'orderNO' => $orderNo,
                'orderId' => $orderId,
                'status' => is_int($status) ? $status : null,
            ];
        }

        return ['records' => $rows, 'total' => $data['total']];
    }

    /** @return array{records:list<array<string,mixed>>,total:int} */
    public function stock(int $page = 1, int $size = 100): array
    {
        $warehouse = trim((string) config('shipout.production_warehouse_id', ''));
        $organization = trim((string) config('shipout.production_client_org_id', ''));
        if ($warehouse === '' || $organization === '') {
            throw new RuntimeException('SHIPOUT_PROD_IDENTITY_UNBOUND');
        }
        $data = $this->page(self::STOCK, [
            'clientOrgId' => $organization, 'warehouseId' => $warehouse,
            'curPageNo' => $page, 'pageSize' => $size,
        ], $page, $size);
        $seen = [];
        $rows = [];
        foreach ($data['records'] as $row) {
            $sku = is_array($row) ? trim((string) ($row['omsSku'] ?? '')) : '';
            if ($sku === '' || isset($seen[$sku])
                || (string) ($row['warehouseId'] ?? '') !== $warehouse
                || ! is_int($row['availableQuantity'] ?? null)
                || $row['availableQuantity'] < 0) {
                throw new RuntimeException('SHIPOUT_PROD_STOCK_UNTRUSTED');
            }
            $seen[$sku] = true;
            $rows[] = [
                'omsSku' => $sku,
                'warehouseId' => $warehouse,
                'availableQuantity' => $row['availableQuantity'],
            ];
        }

        return ['records' => $rows, 'total' => $data['total']];
    }

    /** @return array{records:list<array<string,mixed>>,total:int} */
    private function page(string $path, array $query, int $page, int $size): array
    {
        if ($page < 1 || $size < 1 || $size > 100) {
            throw new RuntimeException('SHIPOUT_PROD_PAGE_INVALID');
        }
        $data = $this->get($path, $query)['data'] ?? null;
        $total = is_array($data) ? ($data['total'] ?? null) : null;
        if (! is_array($data) || ! is_array($data['records'] ?? null)
            || ! array_is_list($data['records'])
            || ! ((is_int($total) && $total >= 0)
                || (is_string($total) && preg_match('/^(0|[1-9][0-9]*)$/', $total) === 1))) {
            throw new RuntimeException('SHIPOUT_PROD_PAGE_UNTRUSTED');
        }
        $total = (int) $total;
        if (count($data['records']) > $size || count($data['records']) > $total
            || ($total > 0 && $page <= (int) ceil($total / $size) && $data['records'] === [])) {
            throw new RuntimeException('SHIPOUT_PROD_PAGE_INCOMPLETE');
        }

        return ['records' => $data['records'], 'total' => $total];
    }

    /** @return array<string,mixed> */
    private function get(string $path, array $query): array
    {
        if (! app()->environment('production')) {
            throw new RuntimeException('SHIPOUT_PROD_ENVIRONMENT_DENIED');
        }
        if (! (bool) config('shipout.production_read_enabled', false)) {
            throw new RuntimeException('SHIPOUT_PROD_READ_DISABLED');
        }
        if (! in_array($path, [self::WAREHOUSES, self::PRODUCTS, self::STOCK, self::ORDER_LIST], true)) {
            throw new RuntimeException('SHIPOUT_PROD_PATH_DENIED');
        }
        $key = trim((string) config('shipout.production_app_key', ''));
        $secret = trim((string) config('shipout.production_app_secret', ''));
        $token = trim((string) config('shipout.production_bearer', ''));
        if (str_starts_with(strtolower($token), 'bearer ')) {
            $token = trim(substr($token, 7));
        }
        if ($key === '' || $secret === '' || $token === '') {
            throw new RuntimeException('SHIPOUT_PROD_CREDENTIAL_MISSING');
        }
        $timestamp = (string) now()->getTimestampMs();
        try {
            $response = Http::acceptJson()
                ->withOptions(['allow_redirects' => false, 'verify' => true])
                ->withHeaders([
                    'Authorization' => 'bearer '.$token, 'appKey' => $key,
                    'timestamp' => $timestamp, 'version' => '1.0.0',
                    'sign' => ShipOutSignature::sign($path, $timestamp, $secret),
                ])
                ->connectTimeout(5)->timeout(15)
                ->get(self::BASE.$path, $query);
        } catch (\Throwable) {
            throw new RuntimeException('SHIPOUT_PROD_TRANSPORT_UNCERTAIN');
        }
        if ($response->status() !== 200) {
            throw new RuntimeException('SHIPOUT_PROD_HTTP_REJECTED');
        }
        $body = $response->json();
        if (! is_array($body) || ($body['result'] ?? null) !== 'OK') {
            throw new RuntimeException('SHIPOUT_PROD_BUSINESS_REJECTED');
        }

        return $body;
    }
}
