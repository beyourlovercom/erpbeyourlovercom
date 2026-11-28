<?php

namespace App\Modules\Logistics\Infrastructure\Wlwy;

use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Fixed production host, read-only methods, exact JSON bytes signed, no redirects or retry. */
final class CanadaReadGateway
{
    public function read(WarehouseOpenApiConnection $connection, string $method, array $body): array
    {
        if (! in_array($method, ['com.basis.warehouse.getlist', 'com.basis.logistics.getlist',
            'fu.wms.inventory.get', 'fu.wms.outbound.getorderstatus', 'fu.wms.outbound.getBoxShippingNos'], true)
            || $connection->provider !== 'wlwy' || $connection->oms_warehouse_code !== 'CA02') {
            throw new RuntimeException('CA_READ_SCOPE_INVALID');
        }
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

    public function exactStatus(WarehouseOpenApiConnection $connection, string $reference): array
    {
        if (trim($reference) === '' || strlen($reference) > 50) {
            throw new RuntimeException('CA_REFERENCE_INVALID');
        }
        $data = $this->read($connection, 'fu.wms.outbound.getorderstatus', ['ref_no' => [$reference]]);
        $rows = $data['consignmentlist'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('CA_STATUS_INCOMPLETE');
        }
        foreach ($rows as $row) {
            if (! is_array($row) || ($row['ref_no'] ?? null) !== $reference
                || ($row['from_warehouse_code'] ?? null) !== 'CA02' || empty($row['consignment_no'])) {
                throw new RuntimeException('CA_STATUS_IDENTITY_CONFLICT');
            }
        }

        return $rows;
    }
}
