<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\CarrierServiceCatalog;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsInventoryReadOnlyGateway;
use RuntimeException;

/** Read-only OMS channel evidence and conservative ERP mapping candidates for DE. */
final class XlwmsWarehouseChannelCatalog
{
    public function __construct(
        private readonly XlwmsInventoryReadOnlyGateway $gateway,
        private readonly CarrierServiceCatalog $carrierCatalog,
    ) {}

    /** @return array<string,mixed> */
    public function previewDe(): array
    {
        $warehouse = Warehouse::query()->where('code', 'DE')->firstOrFail();
        $connection = WarehouseOpenApiConnection::query()
            ->where('warehouse_id', $warehouse->id)->where('provider', 'xlwms')->firstOrFail();
        $whCode = trim((string) $connection->oms_warehouse_code);
        if ($whCode !== 'Hannover') {
            throw new RuntimeException('OMS_DE_WAREHOUSE_CODE_MISMATCH');
        }
        $response = $this->gateway->logisticsChannels($connection, $whCode);

        return self::map($response['data'] ?? null, $this->carrierCatalog->options(), $whCode);
    }

    /** @param mixed $raw @param list<array<string,mixed>> $carrierOptions
     * @return array<string,mixed>
     */
    public static function map(mixed $raw, array $carrierOptions, string $whCode): array
    {
        if (! is_array($raw) || ! array_is_list($raw) || $whCode !== 'Hannover') {
            throw new RuntimeException('OMS_DE_CHANNEL_CATALOG_INVALID');
        }
        $seen = [];
        $items = [];
        $matched = 0;
        $unverified = 0;
        foreach ($raw as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('OMS_DE_CHANNEL_ROW_INVALID');
            }
            $code = trim((string) ($row['channelCode'] ?? ''));
            $name = trim((string) ($row['channelName'] ?? ''));
            $carrierName = trim((string) ($row['carrierName'] ?? ''));
            if ($code === '' || isset($seen[$code])) {
                throw new RuntimeException('OMS_DE_CHANNEL_CODE_INVALID');
            }
            $seen[$code] = true;
            $scopes = $row['whCodes'] ?? [];
            if (is_string($scopes)) {
                $scopes = [$scopes];
            }
            if (! is_array($scopes)) {
                throw new RuntimeException('OMS_DE_CHANNEL_SCOPE_INVALID');
            }
            $scope = in_array($whCode, $scopes, true)
                ? 'verified' : ($scopes === [] ? 'unverified' : 'excluded');
            if ($scope === 'excluded') {
                continue;
            }
            if ($scope === 'unverified') {
                $unverified++;
            }
            $carriers = array_values(array_filter($carrierOptions,
                static fn (array $c): bool => $carrierName !== ''
                    && strcasecmp(trim((string) ($c['value'] ?? '')), $carrierName) === 0));
            $serviceMatches = [];
            if ($scope === 'verified' && count($carriers) === 1) {
                foreach ($carriers[0]['services'] ?? [] as $service) {
                    foreach (['key', 'value', 'name_en', 'name_zh'] as $field) {
                        $candidate = trim((string) ($service[$field] ?? ''));
                        if ($candidate !== '' && (strcasecmp($candidate, $code) === 0
                            || ($name !== '' && strcasecmp($candidate, $name) === 0))) {
                            $serviceMatches[(int) $service['id']] = $service;
                            break;
                        }
                    }
                }
            }
            $resolved = $scope === 'verified' && count($carriers) === 1 && count($serviceMatches) === 1;
            if ($resolved) {
                $matched++;
            }
            $items[] = [
                'oms_channel_code' => $code,
                'oms_channel_name' => $name,
                'oms_channel_type' => (string) ($row['channelType'] ?? ''),
                'oms_carrier_name' => $carrierName,
                'warehouse_scope' => $scope,
                'mapping_status' => $resolved ? 'exact_candidate' : 'unmapped',
                'erp_carrier_name' => $resolved ? $carriers[0]['value'] : null,
                'erp_service_id' => $resolved ? (int) array_key_first($serviceMatches) : null,
            ];
        }

        return [
            'warehouse' => 'DE', 'oms_warehouse_code' => $whCode,
            'total_provider_channels' => count($raw), 'eligible_channels' => count($items),
            'exact_candidate_count' => $matched, 'scope_unverified_count' => $unverified,
            'channels' => $items, 'read_only' => true,
        ];
    }
}
