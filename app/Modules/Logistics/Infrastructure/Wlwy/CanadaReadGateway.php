<?php

namespace App\Modules\Logistics\Infrastructure\Wlwy;

use App\Modules\Logistics\Application\Wlwy\CanadaOutboundBatch;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Fixed production host, read-only methods, exact JSON bytes signed, no redirects or retry. */
final class CanadaReadGateway
{
    public function read(WarehouseOpenApiConnection $connection, string $method, array $body): array
    {
        if (! in_array($method, ['com.basis.warehouse.getlist', 'com.basis.logistics.getlist',
            'fu.wms.inventory.get', 'fu.wms.sku.getlist', 'fu.wms.outbound.getorderstatus', 'fu.wms.outbound.getBoxShippingNos'], true)
            || $connection->provider !== 'wlwy' || $connection->oms_warehouse_code !== 'CA02') {
            throw new RuntimeException('CA_READ_SCOPE_INVALID');
        }

        return $this->request($connection, $method, $body);
    }

    /** Only a persisted, exact in-flight claim can authorize one CREATE. */
    public function create(WarehouseOpenApiConnection $connection, array $body, int $itemId): array
    {
        $claim = DB::table('wlwy_ca_batch_items')->where('id', $itemId)->first();
        if (! app()->environment('production') || ! config('wlwy.ca_dispatch_enabled')
            || ! config('wlwy.ca_dispatch_owner_approved') || ! $claim || $claim->dry_run
            || $claim->status !== 'in_flight' || $claim->reference !== ($body['ref_no'] ?? null)
            || ! hash_equals($claim->payload_hash, hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)))
            || ! CanadaOutboundBatch::liveAllowed((int) $claim->order_id)
            || $connection->provider !== 'wlwy' || $connection->oms_warehouse_code !== 'CA02'
            || ($body['from_warehouse_code'] ?? null) !== 'CA02'
            || ($body['logistics_service_info']['logistics_product_code'] ?? null) !== 'CAPOST'
            || ($body['oconsignment_desc']['country'] ?? null) !== 'CA') {
            throw new RuntimeException('CA_CREATE_NOT_AUTHORIZED');
        }

        return $this->request($connection, 'fu.wms.outbound.create', $body);
    }

    private function request(WarehouseOpenApiConnection $connection, string $method, array $body): array
    {
        $key = (string) $connection->app_key_encrypted;
        $secret = (string) $connection->app_secret_encrypted;
        if ($key === '' || $secret === '') {
            throw new RuntimeException('CA_CREDENTIAL_NOT_CONFIGURED');
        }
        $raw = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) (int) floor(microtime(true) * 1000);
        $sign = md5('app_key'.$key.'formatjsonmethod'.$method.'timestamp'.$timestamp.'v1.0'.$raw.$secret);
        $query = http_build_query(['app_key' => $key, 'format' => 'json', 'method' => $method,
            'timestamp' => $timestamp, 'v' => '1.0', 'sign' => $sign, 'language' => 'en'], '', '&', PHP_QUERY_RFC3986);
        try {
            $response = Http::acceptJson()->withBody($raw, 'application/json')
                ->withOptions(['allow_redirects' => false])->connectTimeout(5)->timeout(20)
                ->post('https://api.wlwytech.com/router/api/service?'.$query);
        } catch (\Throwable) {
            throw new RuntimeException('CA_TRANSPORT_UNCERTAIN');
        }
        if (! $response->successful()) {
            throw new RuntimeException('CA_HTTP_REJECTED');
        }
        $data = $response->json();
        if (! is_array($data) || (string) ($data['result'] ?? '') !== '1'
            || ! empty($data['errors']) || ! is_array($data['data'] ?? null)) {
            throw new RuntimeException('CA_BUSINESS_OR_RESPONSE_REJECTED');
        }

        return $data['data'];
    }

    /** Verify SKU catalog identity and non-batch-management before CREATE. */
    public function assertDispatchCatalog(WarehouseOpenApiConnection $connection, array $skus, string $customer): void
    {
        $data = $this->read($connection, 'fu.wms.sku.getlist', ['lstsku' => $skus]);
        $rows = $data['skulist'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) !== count($skus)) {
            throw new RuntimeException('CA_SKU_CATALOG_INCOMPLETE');
        }
        $seen = [];
        foreach ($rows as $row) {
            $sku = $row['sku_code'] ?? null;
            if (! is_string($sku) || ! in_array($sku, $skus, true) || isset($seen[$sku])
                || ($row['customer_code'] ?? null) !== $customer || empty($row['sku_id'])
                || ($row['include_batch'] ?? null) !== 'N' || ($row['expired_date'] ?? null) !== 'N'
                || filled($row['sn_rule_code'] ?? null)) {
                throw new RuntimeException('CA_SKU_BATCH_OR_IDENTITY_UNVERIFIED');
            }
            $seen[$sku] = true;
        }
    }

    public function exactStatuses(WarehouseOpenApiConnection $connection, array $references): array
    {
        if ($references === [] || count($references) > 1000 || count(array_unique($references)) !== count($references)) {
            throw new RuntimeException('CA_REFERENCE_INVALID');
        }
        foreach ($references as $reference) {
            if (! is_string($reference) || trim($reference) === '' || strlen($reference) > 50) {
                throw new RuntimeException('CA_REFERENCE_INVALID');
            }
        }
        $data = $this->read($connection, 'fu.wms.outbound.getorderstatus', ['ref_no' => $references]);
        $rows = $data['consignmentlist'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('CA_STATUS_INCOMPLETE');
        }
        $result = array_fill_keys($references, []);
        foreach ($rows as $row) {
            if (! is_array($row) || ! in_array($row['ref_no'] ?? null, $references, true)
                || ($row['from_warehouse_code'] ?? null) !== 'CA02' || empty($row['consignment_no'])) {
                throw new RuntimeException('CA_STATUS_IDENTITY_CONFLICT');
            }
            $result[$row['ref_no']][] = $row;
        }

        return $result;
    }

    public function exactStatus(WarehouseOpenApiConnection $connection, string $reference): array
    {
        return $this->exactStatuses($connection, [$reference])[$reference];
    }
}
