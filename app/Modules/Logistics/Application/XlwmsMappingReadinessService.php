<?php

namespace App\Modules\Logistics\Application;

/**
 * P2 configuration-only warehouse binding and SKU resolution.
 *
 * Owner-frozen contract:
 * - Warehouse code must have an explicit ERP -> OMS binding.
 * - ERP SKU is sent unchanged by default (identity resolution).
 * - Only exceptional SKU differences require a per-warehouse override.
 * - Logistics channel/service codes are native vendor codes configured directly
 *   on the ERP carrier catalog; this module must not translate them.
 *
 * No remote catalog or inventory assertion, shipment creation or auto-dispatch.
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
        $skuExceptions = config('xlwms.sku_map', []);
        $blockers = [];
        $remoteCode = null;
        $resolvedSkus = [];

        if (! is_array($warehouseMap) || ! is_array($skuExceptions)) {
            return $this->result(null, [], ['invalid_mapping_configuration']);
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

        $currentExceptions = [];
        foreach ($skuExceptions as $erpWarehouse => $overrides) {
            if (is_string($erpWarehouse) && strtoupper(trim($erpWarehouse)) === $code) {
                $currentExceptions = $overrides;
                break;
            }
        }

        if (! is_array($currentExceptions)) {
            $blockers[] = 'invalid_sku_override_configuration';
        } else {
            foreach ($erpSkus as $erpSku) {
                $erpSku = trim($erpSku);
                if ($erpSku === '') {
                    $blockers[] = 'empty_erp_sku';
                    continue;
                }

                // A missing override is normal: the ERP and vendor SKU match.
                // Explicitly configured bad overrides must fail closed.
                if (array_key_exists($erpSku, $currentExceptions)) {
                    $override = $currentExceptions[$erpSku];
                    if (! is_string($override) || trim($override) === '') {
                        $blockers[] = 'invalid_sku_override:'.$erpSku;
                        continue;
                    }
                    $resolvedSkus[$erpSku] = trim($override);
                } else {
                    $resolvedSkus[$erpSku] = $erpSku;
                }
            }
        }

        if ($erpSkus === []) {
            $blockers[] = 'no_order_skus';
        }

        // This is a local configuration check only. A same-name SKU is NOT proof
        // that the OMS catalog contains it or that inventory is available.
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
