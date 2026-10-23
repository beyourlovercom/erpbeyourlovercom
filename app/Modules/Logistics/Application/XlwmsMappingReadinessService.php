<?php

namespace App\Modules\Logistics\Application;

/**
 * P2 configuration-only mapping gate. It does not claim remote SKU existence,
 * reserve inventory, or create any outbound order.
 */
final class XlwmsMappingReadinessService
{
    /**
     * @param list<string> $erpSkus
     * @return array{status:string,warehouse_code:?string,sku_map:array<string,string>,blockers:list<string>,remote_verified:bool}
     */
    public function assess(string $erpWarehouseCode, array $erpSkus): array
    {
        $code = strtoupper(trim($erpWarehouseCode));
        $warehouseMap = config('xlwms.warehouse_map', []);
        $skuMaps = config('xlwms.sku_map', []);
        $blockers = [];
        $remoteCode = null;
        $resolvedSkus = [];

        if (! is_array($warehouseMap) || ! is_array($skuMaps)) {
            $blockers[] = 'invalid_mapping_configuration';

            return $this->result($remoteCode, $resolvedSkus, $blockers);
        }

        foreach ($warehouseMap as $erp => $remote) {
            if (! is_string($erp) || ! is_string($remote)
                || trim($erp) === '' || trim($remote) === '') {
                $blockers[] = 'invalid_warehouse_mapping';
                break;
            }
            if (strtoupper(trim($erp)) === $code) {
                $remoteCode = trim($remote);
            }
        }

        if ($code === '' || $remoteCode === null) {
            $blockers[] = 'warehouse_not_mapped';
        }

        $warehouseSkuMap = [];
        foreach ($skuMaps as $erpWarehouse => $mappings) {
            if (is_string($erpWarehouse) && strtoupper(trim($erpWarehouse)) === $code) {
                $warehouseSkuMap = $mappings;
                break;
            }
        }

        if (! is_array($warehouseSkuMap)) {
            $blockers[] = 'invalid_sku_mapping';
        } else {
            foreach ($erpSkus as $erpSku) {
                $erpSku = trim($erpSku);
                if ($erpSku === '') {
                    $blockers[] = 'empty_erp_sku';
                    continue;
                }
                $remoteSku = $warehouseSkuMap[$erpSku] ?? null;
                if (! is_string($remoteSku) || trim($remoteSku) === '') {
                    $blockers[] = 'sku_not_mapped:'.$erpSku;
                    continue;
                }
                $resolvedSkus[$erpSku] = trim($remoteSku);
            }
        }

        if ($erpSkus === []) {
            $blockers[] = 'no_order_skus';
        }

        return $this->result($remoteCode, $resolvedSkus, array_values(array_unique($blockers)));
    }

    /**
     * @param array<string,string> $skuMap
     * @param list<string> $blockers
     * @return array{status:string,warehouse_code:?string,sku_map:array<string,string>,blockers:list<string>,remote_verified:bool}
     */
    private function result(?string $remoteCode, array $skuMap, array $blockers): array
    {
        return [
            'status' => $blockers === [] ? 'configured_unverified' : 'blocked',
            'warehouse_code' => $remoteCode,
            'sku_map' => $skuMap,
            'blockers' => $blockers,
            'remote_verified' => false,
        ];
    }
}
