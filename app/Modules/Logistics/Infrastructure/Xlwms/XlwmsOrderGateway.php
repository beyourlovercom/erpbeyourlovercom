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
        if (! in_array($path, ['outboundOrder/detail', 'outboundOrder/create', 'warehouse/options', 'logistics/channel/list', 'product/pagelist'], true)) {
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
                ->withOptions(['allow_redirects' => false])
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

    /** @return list<array<string,mixed>> */
    public function findExisting(WarehouseOpenApiConnection $connection, string $thirdOrderNo, ?string $platformOrderNo = null): array
    {
        $query = ['thirdOrderNoList' => [$thirdOrderNo]];
        if ($platformOrderNo !== null && $platformOrderNo !== '') {
            $query['platformOrderNoList'] = [$platformOrderNo];
        }
        $data = $this->signedPost($connection, 'outboundOrder/detail', $query);
        if (! is_array($data['data'] ?? null)) throw new RuntimeException('OMS_DETAIL_RESPONSE_INVALID');
        $rows = array_values(array_filter($data['data'], 'is_array'));
        // A Woo-imported OMS record can have a DIFFERENT thirdOrderNo.
        // Either exact reference blocks duplicate creation; never fuzzy match.
        return array_values(array_filter($rows, static fn (array $row): bool =>
            (string) ($row['thirdOrderNo'] ?? '') === $thirdOrderNo
            || ($platformOrderNo !== null && $platformOrderNo !== ''
                && (string) ($row['platformOrderNo'] ?? '') === $platformOrderNo)
        ));
    }

    /** Verify exact OMS native channel and every requested SKU without writes. */
    public function assertCatalogReady(WarehouseOpenApiConnection $connection, string $whCode, string $channel, array $skuList): void
    {
        if ($whCode === '' || $channel === '' || $skuList === [] || count($skuList) > 100) {
            throw new RuntimeException('OMS_CATALOG_INPUT_INVALID');
        }
        $channels = $this->signedPost($connection, 'logistics/channel/list', ['whCode' => $whCode]);
        $channelRecords = $channels['data'] ?? null;
        if (! is_array($channelRecords)
            || ! collect($channelRecords)->contains(static fn($r): bool =>
                is_array($r) && ($r['channelCode'] ?? null) === $channel
                && (! isset($r['whCodes']) || in_array($whCode, (array) $r['whCodes'], true)))) {
            throw new RuntimeException('OMS_CHANNEL_NOT_AVAILABLE');
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

    /** Read-only vendor catalog proof. Does not establish physical equivalence. */
    public function assertSkuApproved(WarehouseOpenApiConnection $connection, string $sku): void
    {
        if ($sku === '' || strlen($sku) > 128) {
            throw new RuntimeException('OMS_SKU_INVALID');
        }
        $data=$this->signedPost($connection, 'product/pagelist', [
            'page'=>1,'pageSize'=>100,'skuList'=>[$sku],
        ]);
        $records=$data['data']['records'] ?? null;
        if (! is_array($records) || ! collect($records)->contains(
            static fn ($row): bool=>is_array($row)
                && (string)($row['sku']??'')===$sku
                && (! isset($row['approveStatus']) || (int)$row['approveStatus']===2)
        )) {
            throw new RuntimeException('OMS_SKU_NOT_APPROVED_OR_MISSING');
        }
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
