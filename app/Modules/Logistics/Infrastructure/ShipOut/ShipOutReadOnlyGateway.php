<?php

namespace App\Modules\Logistics\Infrastructure\ShipOut;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * R2/R5 bridge foundation. SANDBOX-only and GET-only until real sandbox
 * outbound acceptance, credential rotation, and Owner Production PASS.
 * No default credential, write method, arbitrary URL, or ERP stock mutation.
 */
final class ShipOutReadOnlyGateway
{
    private const BASE = 'https://opendev.shipout.com/api';
    private const WAREHOUSES = '/open-api/oms/info/warehouse/list';
    private const STOCK = '/open-api/oms/stock/list';
    private const PRODUCTS = '/open-api/oms/product/queryList';
    private const ORDER_LIST = '/open-api/oms/order/queryListV2';
    private const ORDER_DETAIL = '/open-api/oms/order/query';

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
    public function productPage(int $page = 1, int $size = 50): array
    {
        if ($page < 1 || $size < 1 || $size > 100) {
            throw new RuntimeException('SHIPOUT_PRODUCT_QUERY_INVALID');
        }
        $response = $this->get(self::PRODUCTS, ['curPageNo' => $page, 'pageSize' => $size]);
        $data = $response['data'] ?? null;
        if (! is_array($data) || ! isset($data['records'], $data['total'])
            || ! is_array($data['records']) || ! array_is_list($data['records'])
            || ! (is_int($data['total']) && $data['total'] >= 0
                || is_string($data['total']) && preg_match('/^(0|[1-9][0-9]*)$/', $data['total']) === 1)) {
            throw new RuntimeException('SHIPOUT_PRODUCT_RESPONSE_INCOMPLETE');
        }
        $total = (int) $data['total'];
        if (count($data['records']) > $size || count($data['records']) > $total
            || ($total > 0 && $page <= (int) ceil($total / $size) && $data['records'] === [])) {
            throw new RuntimeException('SHIPOUT_PRODUCT_PAGE_INCOMPLETE');
        }
        $seen = [];
        $rows = [];
        foreach ($data['records'] as $row) {
            if (! is_array($row) || trim((string) ($row['omsSku'] ?? '')) === '') {
                throw new RuntimeException('SHIPOUT_PRODUCT_ROW_UNTRUSTED');
            }
            $sku = trim((string) $row['omsSku']);
            if (isset($seen[$sku])) {
                throw new RuntimeException('SHIPOUT_PRODUCT_DUPLICATE_SKU');
            }
            $seen[$sku] = true;
            $rows[] = [
                'omsSku' => $sku,
                'skuId' => (string) ($row['skuId'] ?? ''),
                'skuNameEN' => (string) ($row['skuNameEN'] ?? ''),
                'status' => $row['status'] ?? null,
                'warehouseIds' => $row['warehouseIds'] ?? [],
            ];
        }
        return ['records' => $rows, 'total' => $total];
    }

    /** Read-only order page: only whitelisted status and tracking metadata, not recipient PII.
     * @return array{records:list<array<string,mixed>>,total:int}
     */
    public function orderPage(string $warehouseId, string $orderNo, int $page = 1, int $size = 50): array
    {
        if (trim($warehouseId) === '' || trim($orderNo) === ''
            || $page < 1 || $size < 1 || $size > 100) {
            throw new RuntimeException('SHIPOUT_ORDER_QUERY_INVALID');
        }

        $response = $this->get(self::ORDER_LIST, [
            'warehouseId' => $warehouseId, 'orderNO' => $orderNo,
            'version' => 'v2', 'curPageNo' => $page, 'pageSize' => $size,
        ]);
        $data = $response['data'] ?? null;
        if (! is_array($data) || ! isset($data['records'], $data['total'])
            || ! is_array($data['records']) || ! array_is_list($data['records'])
            || ! (is_int($data['total']) && $data['total'] >= 0
                || is_string($data['total']) && preg_match('/^(0|[1-9][0-9]*)$/', $data['total']) === 1)) {
            throw new RuntimeException('SHIPOUT_ORDER_RESPONSE_INCOMPLETE');
        }
        $total = (int) $data['total'];
        if (count($data['records']) > $size || count($data['records']) > $total
            || ($total > 0 && $page === 1 && $data['records'] === [])) {
            throw new RuntimeException('SHIPOUT_ORDER_PAGE_INCOMPLETE');
        }

        $rows = [];
        foreach ($data['records'] as $row) {
            if (! is_array($row) || trim((string) ($row['orderNO'] ?? '')) !== $orderNo
                || trim((string) ($row['orderId'] ?? '')) === '') {
                throw new RuntimeException('SHIPOUT_ORDER_ROW_UNTRUSTED');
            }
            $rows[] = [
                'orderNO' => $orderNo,
                'orderId' => (string) $row['orderId'],
                'status' => $row['status'] ?? null,
                'cancelReasonCode' => $this->safeCancelReasonCode($row['cancelReason'] ?? null),
                // Cancelled/aborted orders can have unassigned shipment.warehouseId.
                // Do not mistake their incomplete shipment for a shipped parcel.
                'shipments' => in_array((int) ($row['status'] ?? -1), [6, 11], true)
                    ? [] : $this->sanitizeShipments($row['shipmentList'] ?? [], $warehouseId),
            ];
        }
        return ['records' => $rows, 'total' => $total];
    }

    /** Order detail: safely project actual carrier and provider tracking fields. */
    public function orderDetail(string $warehouseId, string $orderId): array
    {
        if (trim($warehouseId) === '' || trim($orderId) === '') {
            throw new RuntimeException('SHIPOUT_ORDER_QUERY_INVALID');
        }
        $response = $this->get(self::ORDER_DETAIL, [
            'warehouseId' => $warehouseId, 'orderId' => $orderId, 'version' => 'v2',
        ]);
        $data = $response['data'] ?? null;
        if (! is_array($data) || (string) ($data['orderId'] ?? '') !== $orderId
            || trim((string) ($data['orderNO'] ?? '')) === '') {
            throw new RuntimeException('SHIPOUT_ORDER_DETAIL_UNTRUSTED');
        }
        return [
            'orderId' => $orderId,
            'orderNO' => (string) $data['orderNO'],
            'status' => $data['status'] ?? null,
            'cancelReasonCode' => $this->safeCancelReasonCode($data['cancelReason'] ?? null),
            // Verified on real ShipOut Sandbox: queryListV2 => shipmentList,
            // order/query => orderShipments (do NOT interchange).
            'shipments' => in_array((int) ($data['status'] ?? -1), [6, 11], true)
                ? [] : $this->sanitizeShipments($data['orderShipments'] ?? [], $warehouseId),
        ];
    }

    /** Project only known operational failure codes; never expose free-text
     * provider reasons (which could include customer PII or trace content).
     */
    private function safeCancelReasonCode(mixed $reason): ?string
    {
        if (! is_string($reason) || $reason === '') {
            return null;
        }
        if (stripos($reason, 'Account Balance or Credit insufficient') !== false
            || str_contains($reason, '账户余额或信用额度不足')) {
            return 'PROVIDER_ACCOUNT_BALANCE_INSUFFICIENT';
        }
        return 'PROVIDER_CANCELLED_OTHER';
    }

    /** Never return recipient/address/phone fields or trust another warehouse. */
    private function sanitizeShipments(mixed $shipments, string $warehouseId): array
    {
        if (! is_array($shipments) || ! array_is_list($shipments)) {
            throw new RuntimeException('SHIPOUT_SHIPMENTS_UNTRUSTED');
        }
        $result = [];
        foreach ($shipments as $row) {
            if (! is_array($row) || (string) ($row['warehouseId'] ?? '') !== $warehouseId) {
                throw new RuntimeException('SHIPOUT_SHIPMENT_WAREHOUSE_MISMATCH');
            }
            $result[] = [
                'shipmentId' => (string) ($row['shipmentId'] ?? ''),
                'outboundNO' => (string) ($row['outboundNO'] ?? ''),
                'outboundStatus' => $row['outboundStatus'] ?? null,
                'labelStatus' => $row['labelStatus'] ?? null,
                'carrierId' => $row['carrierId'] ?? null,
                'trackingNumber' => (string) ($row['trackingNumber'] ?? ''),
                'shippedTime' => $row['shippedTime'] ?? null,
            ];
        }
        return $result;
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
        // Production must use separately audited credentials/transport.
        if (app()->environment('production')) {
            throw new RuntimeException('SHIPOUT_SANDBOX_FORBIDDEN_IN_PRODUCTION');
        }
        if (! (bool) config('shipout.sandbox_read_enabled', false)) {
            throw new RuntimeException('SHIPOUT_SANDBOX_READ_DISABLED');
        }
        if (! in_array($path, [self::WAREHOUSES, self::STOCK, self::PRODUCTS, self::ORDER_LIST, self::ORDER_DETAIL], true)) {
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
