<?php

namespace App\Modules\Logistics\Infrastructure\Xlwms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fixed OMS v1 endpoint allowlist. Writes are independently gated in the
 * application service. No dynamic URL, redirect or middleware queue retries.
 */
final class XlwmsOrderGateway
{
    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function signedPost(WarehouseOpenApiConnection $connection, string $path, array $data): array
    {
        if (! in_array($path, ['outboundOrder/detail', 'outboundOrder/pageList', 'outboundOrder/create', 'warehouse/options', 'logistics/channel/list', 'product/pagelist'], true)) {
            throw new RuntimeException('OMS operation not allowlisted.');
        }
        $appKey = (string) $connection->app_key_encrypted;
        $secret = (string) $connection->app_secret_encrypted;
        if ($appKey === '' || $secret === '') {
            throw new RuntimeException('OMS credential not configured.');
        }
        $reqTime = (string) time();
        $normalized = $path === 'warehouse/options' ? null : XlwmsSignature::normalize($data);
        $authcode = XlwmsSignature::sign($appKey, $secret, $reqTime, $normalized);
        $payload = ['appKey' => $appKey, 'reqTime' => $reqTime];
        if ($normalized !== null) $payload['data'] = $normalized;

        try {
            $response = Http::acceptJson()->asJson()
                ->withOptions(['allow_redirects' => false, 'verify' => config('xlwms.ca_bundle') ?: true])
                ->connectTimeout(5)->timeout(15)
                ->post('https://api.xlwms.com/openapi/v1/'.$path.'?authcode='.$authcode, $payload);
        } catch (\Throwable) {
            // A transport exception can contain the complete authcode query string.
            throw new RuntimeException('OMS_TRANSPORT_UNCERTAIN');
        }
        if ($response->status() >= 500) throw new RuntimeException('OMS_SERVER_UNCERTAIN');
        if (! $response->successful()) throw new RuntimeException('OMS_HTTP_REJECTED');
        $json = $response->json();
        if (! is_array($json) || (int) ($json['code'] ?? -1) !== 200) {
            throw new RuntimeException('OMS_BUSINESS_REJECTED');
        }
        return $json;
    }

    /** @return list<array<string,mixed>> */
    public function warehouseOptions(WarehouseOpenApiConnection $connection): array
    {
        $data = $this->signedPost($connection, 'warehouse/options', []);
        if (! is_array($data['data'] ?? null)) throw new RuntimeException('OMS_WAREHOUSE_RESPONSE_INVALID');
        return array_values(array_filter($data['data'], 'is_array'));
    }

    /**
     * Check both API-origin and MANUALLY created OMS orders. A manual record
     * may have no thirdOrderNo / platformOrderNo and only referOrderNo.
     *
     * Never treat an incomplete page scan as "no duplicate". Keep the
     * read-only scan bounded: a warehouse with >500 rows needs an
     * independently verified exact-search provider contract before creating.
     *
     * @param list<string> $referenceNos Exact confirmed ERP/Woo references only.
     * @return list<array<string,mixed>>
     */
    public function findExisting(
        WarehouseOpenApiConnection $connection,
        string $thirdOrderNo,
        ?string $platformOrderNo = null,
        array $referenceNos = []
    ): array {
        $refs = array_values(array_unique(array_filter(array_map(
            static fn ($r): string => trim((string) $r),
            [$thirdOrderNo, $platformOrderNo, ...$referenceNos],
        ), static fn (string $s): bool => $s !== '')));
        if ($thirdOrderNo === '' || count($refs) > 8) {
            throw new RuntimeException('OMS_DUPLICATE_LOOKUP_INPUT_INVALID');
        }

        // One identifier per detail request. Some providers implement multiple
        // list fields as AND rather than OR, silently missing old manual rows.
        foreach ([
            ['thirdOrderNoList' => [$thirdOrderNo]],
            ...($platformOrderNo ? [['platformOrderNoList' => [$platformOrderNo]]] : []),
        ] as $query) {
            $detail = $this->signedPost($connection, 'outboundOrder/detail', $query);
            $records = $detail['data'] ?? null;
            if (! is_array($records)) throw new RuntimeException('OMS_DETAIL_RESPONSE_INVALID');
            $found = $this->exactMatches($records, $refs);
            if ($found !== []) return $found;
        }

        // Historical/manual warehouse orders are only discoverable by
        // referOrderNo in the paginated outbound order list.
        $pageSize = 10;
        for ($page = 1; $page <= 50; $page++) {
            $response = $this->signedPost($connection, 'outboundOrder/pageList', [
                'page' => $page, 'pageSize' => $pageSize,
            ]);
            $data = $response['data'] ?? null;
            if (! is_array($data)) throw new RuntimeException('OMS_PAGE_RESPONSE_INVALID');
            $rows = $data['records'] ?? $data['list'] ?? $data['rows'] ?? (array_is_list($data) ? $data : null);
            if (! is_array($rows)) throw new RuntimeException('OMS_PAGE_RESPONSE_INVALID');
            $matched = $this->exactMatches($rows, $refs);
            if ($matched !== []) return $matched;
            $total = $data['total'] ?? $data['totalCount'] ?? null;
            if (count($rows) < $pageSize) return [];
            if (is_numeric($total) && $page * $pageSize >= (int) $total) return [];
        }
        throw new RuntimeException('OMS_DUPLICATE_SCAN_INCOMPLETE');
    }

    /**
     * Exact existing-order identity including SKU quantities. The official
     * outboundOrder/detail response defines productList[].sku and
     * productList[].quantity (integer). Missing/ambiguous quantities fail
     * closed; matching only a SKU set could ship a wrong quantity.
     *
     * @param list<array{sku:string,quantity:int}> $expectedItems
     */
    public function findExistingExact(
        WarehouseOpenApiConnection $connection,
        string $thirdOrderNo,
        string $platformOrderNo,
        array $referenceNos,
        array $expectedItems,
    ): array {
        $expected = $this->exactItemQuantities($expectedItems);
        if ($expected === null || $expected === []) {
            throw new RuntimeException('OMS_ITEM_QUANTITY_INVALID');
        }
        $matches = $this->findExisting($connection, $thirdOrderNo, $platformOrderNo, $referenceNos);
        $strict = array_values(array_filter($matches, function (array $row) use ($connection, $expected, $thirdOrderNo, $platformOrderNo): bool {
            if (($row['whCode'] ?? $row['warehouseCode'] ?? null) !== $connection->oms_warehouse_code) {
                return false;
            }
            if (trim((string) ($row['thirdOrderNo'] ?? '')) !== trim($thirdOrderNo)
                || trim((string) ($row['platformOrderNo'] ?? '')) !== trim($platformOrderNo)) {
                return false;
            }
            $actual = $this->exactItemQuantities($row['productList'] ?? $row['products'] ?? null);
            return $actual !== null && $actual === $expected;
        }));
        if ($matches !== [] && $strict === []) throw new RuntimeException('OMS_STATUS_IDENTITY_MISMATCH');
        if (count($strict) > 1) throw new RuntimeException('OMS_DUPLICATE_AMBIGUOUS');
        return $strict;
    }

    /** @return array<string,int>|null */
    private function exactItemQuantities(mixed $items): ?array
    {
        if (! is_array($items) || ! array_is_list($items) || $items === []) return null;
        $quantities = [];
        foreach ($items as $item) {
            if (! is_array($item)) return null;
            $sku = $item['sku'] ?? null;
            $qty = $item['quantity'] ?? null;
            if (! is_string($sku) || trim($sku) === ''
                || ! (is_int($qty) || (is_string($qty) && preg_match('/^[1-9][0-9]*$/D', $qty)))
                || (int)$qty < 1 || (int)$qty > 1000000) return null;
            $sku = trim($sku);
            $quantities[$sku] = ($quantities[$sku] ?? 0) + (int)$qty;
            if ($quantities[$sku] > 1000000) return null;
        }
        ksort($quantities);
        return $quantities;
    }

    /**
     * Strict exact identifier match only. Never fuzzy match recipient/address.
     * Read-only vendor result may use multiple reference field aliases.
     *
     * @param array<mixed> $rows
     * @param list<string> $refs
     * @return list<array<string,mixed>>
     */
    private function exactMatches(array $rows, array $refs): array
    {
        $matched = [];
        foreach ($rows as $row) {
            if (! is_array($row)) continue;
            foreach ([
                'thirdOrderNo', 'platformOrderNo', 'referOrderNo',
                'referenceOrderNo', 'referenceNo',
            ] as $field) {
                $value = trim((string) ($row[$field] ?? ''));
                if ($value !== '' && in_array($value, $refs, true)) {
                    $row['_erp_match_field'] = $field;
                    $matched[] = $row;
                    break;
                }
            }
        }
        return $matched;
    }

    /**
     * Read ONE known OMS outbound order by its provider-issued number.
     * pageList supports exact outboundOrderNos; 10 rows is the LIVE tested
     * vendor page limit. Never confuse an omitted/partial response with success.
     *
     * @return array{order_no:string,third_order_no:string,refer_order_no:string,platform_order_no:string,status_code:string,tracking_no:?string}|null
     */
    public function readExactOrderStatus(WarehouseOpenApiConnection $connection, string $omsOrderNo): ?array
    {
        if (! preg_match('/^[A-Za-z0-9_-]{6,100}$/', $omsOrderNo)
            || ! filled($connection->oms_warehouse_code)) {
            throw new RuntimeException('OMS_STATUS_QUERY_INVALID');
        }
        $json = $this->signedPost($connection, 'outboundOrder/pageList', [
            'page' => 1, 'pageSize' => 10, 'outboundOrderNos' => $omsOrderNo,
        ]);
        $data = $json['data'] ?? null;
        if (! is_array($data) || ! isset($data['records'])
            || ! is_array($data['records'])
            || ! isset($data['total']) || ! is_numeric($data['total'])
            || count($data['records']) > 10 || (int) $data['total'] > 1) {
            throw new RuntimeException('OMS_STATUS_RESPONSE_INVALID');
        }
        if ((int)$data['total'] === 0 && count($data['records']) === 0) {
            return null;
        }
        if ((int)$data['total'] !== 1 || count($data['records']) !== 1) {
            throw new RuntimeException('OMS_STATUS_RESPONSE_INVALID');
        }
        $item = $data['records'][0];
        if (! is_array($item)
            || ($item['outboundOrderNo'] ?? null) !== $omsOrderNo
            || ($item['whCode'] ?? null) !== $connection->oms_warehouse_code) {
            throw new RuntimeException('OMS_STATUS_IDENTITY_MISMATCH');
        }
        $status = $item['status'] ?? null;
        if ((! is_int($status) && ! is_string($status))
            || ! preg_match('/^[0-9]{1,2}$/', (string) $status)) {
            throw new RuntimeException('OMS_STATUS_RESPONSE_INVALID');
        }
        // Do not accept arbitrary arrays or large untrusted tracking data.
        $tracking = $item['logisticsTrackNo'] ?? null;
        $tracking = is_string($tracking) ? trim($tracking) : null;
        if ($tracking !== null && strlen($tracking) > 120) {
            throw new RuntimeException('OMS_STATUS_RESPONSE_INVALID');
        }

        // Provider-native label only. It is not proof of the physical
        // last-mile carrier, and must never trigger a shipment write.
        $carrier = $item['logisticsCarrier'] ?? null;
        if ($carrier !== null && (! is_string($carrier) || strlen($carrier) > 120)) {
            throw new RuntimeException('OMS_STATUS_RESPONSE_INVALID');
        }
        $carrier = is_string($carrier) ? trim($carrier) : null;

        return [
            'carrier_raw' => $carrier ?: null,
            'order_no' => $omsOrderNo,
            'third_order_no' => trim((string)($item['thirdOrderNo'] ?? '')),
            'refer_order_no' => trim((string)($item['referOrderNo'] ?? '')),
            'platform_order_no' => trim((string)($item['platformOrderNo'] ?? '')),
            'status_code' => (string)$status,
            'tracking_no' => $tracking ?: null,
        ];
    }

    /** Verify exact OMS native channel and every requested SKU without writes. */
    public function assertCatalogReady(WarehouseOpenApiConnection $connection, string $whCode, string $channel, array $skuList, bool $requireNoShipping = false): void
    {
        if ($whCode === '' || $channel === '' || $skuList === [] || count($skuList) > 100) {
            throw new RuntimeException('OMS_CATALOG_INPUT_INVALID');
        }
        $channels = $this->signedPost($connection, 'logistics/channel/list', ['whCode' => $whCode]);
        $channelRecords = $channels['data'] ?? null;
        if (! is_array($channelRecords)
            || ! collect($channelRecords)->contains(static fn($r): bool =>
                is_array($r) && ($r['channelCode'] ?? null) === $channel
                && (! isset($r['whCodes']) || (array) $r['whCodes'] === [] || in_array($whCode, (array) $r['whCodes'], true)))) {
            throw new RuntimeException('OMS_CHANNEL_NOT_AVAILABLE');
        }
        if ($requireNoShipping) {
            $record = collect($channelRecords)->first(static fn ($r): bool => is_array($r)
                && ($r['channelCode'] ?? null) === $channel);
            // Exact real provider catalogue: type 2, name 无需物流服务.
            // Never treat special type 3 Upload_Shipping_Label or type 1
            // cheapest-price as equivalent. This does not imply shipped.
            if (! is_array($record)
                || $channel !== 'No_Shipping_Service'
                || (string)($record['channelType'] ?? '') !== '2'
                || trim((string)($record['channelName'] ?? '')) !== '无需物流服务') {
                throw new RuntimeException('US_SM_NO_SHIPPING_CHANNEL_UNAVAILABLE');
            }
        }
        $products = $this->signedPost($connection, 'product/pagelist', [
            'page' => 1, 'pageSize' => 100, 'skuList' => array_values(array_unique($skuList)),
        ]);
        $records = $products['data']['records'] ?? null;
        if (! is_array($records)) throw new RuntimeException('OMS_PRODUCT_CATALOG_UNAVAILABLE');
        foreach ($skuList as $sku) {
            $exists = collect($records)->contains(static fn($r): bool =>
                is_array($r) && ($r['sku'] ?? null) === $sku
                && (! isset($r['approveStatus']) || (int) $r['approveStatus'] === 2));
            if (! $exists) throw new RuntimeException('OMS_SKU_NOT_APPROVED_OR_MISSING');
        }
    }

    /**
     * T2 isolated non-submitting TEST draft. Only a local UAT runner may
     * invoke this method after its own one-shot safety gate is satisfied.
     * Must NEVER fallback to the normal isSubmit=1 createSingle method.
     *
     * @param array<string,mixed> $draft
     * @return array{confirmed:bool,order_no:?string}
     */
    public function createTestDraftSingle(WarehouseOpenApiConnection $connection, array $draft): array
    {
        $reference = (string)($draft['referOrderNo'] ?? '');
        $items = $draft['productList'] ?? null;
        if (! app()->environment(['local','testing'])
            || (int)($draft['isSubmit'] ?? -1) !== 0
            || ! preg_match('/^TEST-XLWMS-[0-9]{3,12}$/', $reference)
            || (string)($draft['platformOrderNo'] ?? '') !== $reference
            || ! preg_match('/^BYL-TEST-[0-9]+$/', (string)($draft['thirdOrderNo'] ?? ''))
            || ! str_contains(strtoupper((string)($draft['receiver'] ?? '')), 'DO NOT SHIP')
            || ! is_array($items) || count($items) !== 1
            || (int)($items[0]['quantity'] ?? 0) !== 1
            || ! filled($draft['whCode'] ?? null)
            || ! filled($draft['logisticsChannel'] ?? null)
        ) {
            throw new RuntimeException('OMS_TEST_DRAFT_SAFETY_CONTRACT_INVALID');
        }
        $data = $this->signedPost($connection, 'outboundOrder/create', [$draft]);
        $matches = array_values(array_filter(
            (array)($data['data'] ?? []),
            static fn($row): bool => is_array($row)
                && ($row['thirdOrderNo'] ?? null) === ($draft['thirdOrderNo'] ?? null)
        ));
        if (count($matches) !== 1 || ($matches[0]['success'] ?? null) !== true
            || empty($matches[0]['orderNo'])) {
            throw new RuntimeException('OMS_TEST_DRAFT_CREATE_UNCONFIRMED');
        }
        return ['confirmed'=>true,'order_no'=>(string)$matches[0]['orderNo']];
    }

    /** Production draft-only creation. Unlike submit, this does NOT send
     * the order into the warehouse process. Idempotency/identity enforcement
     * is performed by the calling application service before this endpoint.
     * @param array<string,mixed> $draft
     * @return array{confirmed:bool,order_no:string}
     */
    public function createDraftSingle(WarehouseOpenApiConnection $connection, array $draft): array
    {
        if (count($draft) < 1
            || ($draft['isSubmit'] ?? null) !== 0
            || blank($draft['thirdOrderNo'] ?? null)
            || blank($draft['whCode'] ?? null)
            || ($draft['logisticsChannel'] ?? null) !== 'No_Shipping_Service') {
            throw new RuntimeException('OMS_DRAFT_CONTRACT_INVALID');
        }
        $data = $this->signedPost($connection, 'outboundOrder/create', [$draft]);
        $matches = array_values(array_filter(
            (array) ($data['data'] ?? []),
            static fn (mixed $row): bool => is_array($row)
                && ($row['thirdOrderNo'] ?? null) === ($draft['thirdOrderNo'] ?? null)
        ));
        if (count($matches) !== 1 || ($matches[0]['success'] ?? null) !== true
            || blank($matches[0]['orderNo'] ?? null)) {
            throw new RuntimeException('OMS_DRAFT_CREATE_NOT_CONFIRMED');
        }
        return ['confirmed'=>true,'order_no'=>(string)$matches[0]['orderNo']];
    }

    /** @param array<string,mixed> $singleOrder
     * @return array{confirmed:bool,order_no:?string}
     */
    public function createSingle(WarehouseOpenApiConnection $connection, array $singleOrder): array
    {
        if (count($singleOrder) < 1 || ($singleOrder['isSubmit'] ?? null) !== 1) {
            throw new RuntimeException('OMS_SUBMIT_CONTRACT_INVALID');
        }
        $data = $this->signedPost($connection, 'outboundOrder/create', [$singleOrder]);
        $matches = array_values(array_filter(
            (array) ($data['data'] ?? []),
            static fn (mixed $row): bool => is_array($row)
                && ($row['thirdOrderNo'] ?? null) === ($singleOrder['thirdOrderNo'] ?? null)
        ));
        if (count($matches) !== 1 || ($matches[0]['success'] ?? null) !== true) {
            throw new RuntimeException('OMS_CREATE_NOT_CONFIRMED');
        }
        return ['confirmed' => true, 'order_no' => (string) ($matches[0]['orderNo'] ?? '') ?: null];
    }
}
