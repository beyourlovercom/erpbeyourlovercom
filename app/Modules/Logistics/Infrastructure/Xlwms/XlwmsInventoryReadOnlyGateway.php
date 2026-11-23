<?php

namespace App\Modules\Logistics\Infrastructure\Xlwms;

use App\Modules\Logistics\Application\Xlwms\XlwmsInventoryGateway;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Strict inventory and logistics-catalog read-only OMS adapter; no outbound operations. */
final class XlwmsInventoryReadOnlyGateway implements XlwmsInventoryGateway
{
    private const BASE_URL = 'https://api.xlwms.com/openapi/v1';
    private const ALLOWED_PATHS = ['product/pagelist', 'integratedInventory/pageOpen', 'logistics/channel/list'];

    public function productPage(WarehouseOpenApiConnection $connection, array $data): array
    {
        return $this->signedPost($connection, 'product/pagelist', $data);
    }

    public function integratedInventoryPage(WarehouseOpenApiConnection $connection, array $data): array
    {
        return $this->signedPost($connection, 'integratedInventory/pageOpen', $data);
    }

    /** @return array<string,mixed> */
    public function logisticsChannels(WarehouseOpenApiConnection $connection, string $omsWarehouseCode): array
    {
        if ($omsWarehouseCode === '') throw new RuntimeException('OMS_WAREHOUSE_CODE_NOT_CONFIGURED');
        return $this->signedPost($connection, 'logistics/channel/list', ['whCode' => $omsWarehouseCode]);
    }

    /** @return array<string,mixed> */
    private function signedPost(WarehouseOpenApiConnection $connection, string $path, array $data): array
    {
        if (! in_array($path, self::ALLOWED_PATHS, true)) {
            throw new RuntimeException('OMS_OPERATION_NOT_ALLOWLISTED');
        }
        $appKey = trim((string) $connection->app_key_encrypted);
        $secret = (string) $connection->app_secret_encrypted;
        if ($appKey === '' || $secret === '') {
            throw new RuntimeException('OMS_CREDENTIAL_NOT_CONFIGURED');
        }
        $reqTime = (string) time();
        $normalized = XlwmsSignature::normalize($data);
        $authcode = XlwmsSignature::sign($appKey, $secret, $reqTime, $normalized);
        try {
            $response = Http::acceptJson()->asJson()
                ->withOptions(['allow_redirects' => false, 'verify' => config('xlwms.ca_bundle') ?: true])
                ->connectTimeout(5)->timeout((int) config('xlwms.timeout_seconds', 15))
                ->post(self::BASE_URL.'/'.$path.'?authcode='.$authcode, [
                    'appKey' => $appKey, 'reqTime' => $reqTime, 'data' => $normalized,
                ]);
        } catch (\Throwable) {
            throw new RuntimeException('OMS_TRANSPORT_UNCERTAIN');
        }
        if ($response->status() !== 200) throw new RuntimeException('OMS_HTTP_REJECTED');
        $json = $response->json();
        if (! is_array($json) || (int) ($json['code'] ?? -1) !== 200) {
            throw new RuntimeException('OMS_BUSINESS_REJECTED');
        }
        return $json;
    }
}
