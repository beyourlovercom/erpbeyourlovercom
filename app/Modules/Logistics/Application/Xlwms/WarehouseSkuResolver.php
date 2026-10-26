<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseSkuMapping;

/**
 * Fulfillment-only translation. Never mutate canonical ERP SKU, product,
 * Woo item/source SKU, inventory, pricing or product aliases.
 */
final class WarehouseSkuResolver
{
    /**
     * @param list<string> $erpSkus
     * @return array{resolved:array<string,string>,blockers:list<string>,rows:list<array{erp_sku:string,oms_sku:string,status:string,source:string}>}
     */
    public function resolve(?int $warehouseId, array $erpSkus): array
    {
        $existing = $warehouseId
            ? WarehouseSkuMapping::query()->where('warehouse_id', $warehouseId)
                ->where('provider', 'xlwms')->get()->keyBy('erp_sku_norm')
            : collect();

        $resolved = [];
        $blockers = [];
        $rows = [];
        foreach ($erpSkus as $raw) {
            $erpSku = trim($raw);
            if ($erpSku === '') {
                $blockers[] = 'empty_erp_sku';
                continue;
            }
            $mapping = $existing->get(mb_strtoupper($erpSku));
            if ($mapping && $mapping->status !== 'active') {
                $blockers[] = 'sku_mapping_pending_confirmation:'.$erpSku;
                $rows[] = ['erp_sku'=>$erpSku,'oms_sku'=>$mapping->oms_sku,'status'=>$mapping->status,'source'=>'exception'];
                $resolved[$erpSku] = $erpSku; // cannot dispatch while blocked
            } elseif ($mapping && ! $mapping->isConfirmed()) {
                $blockers[] = 'sku_mapping_missing_evidence:'.$erpSku;
                $rows[] = ['erp_sku'=>$erpSku,'oms_sku'=>$mapping->oms_sku,'status'=>'invalid','source'=>'exception'];
                $resolved[$erpSku] = $erpSku;
            } elseif ($mapping) {
                $resolved[$erpSku] = trim((string) $mapping->oms_sku);
                $rows[] = ['erp_sku'=>$erpSku,'oms_sku'=>$resolved[$erpSku],'status'=>'active','source'=>'exception'];
            } else {
                $resolved[$erpSku] = $erpSku; // identity by default
                $rows[] = ['erp_sku'=>$erpSku,'oms_sku'=>$erpSku,'status'=>'identity','source'=>'identity'];
            }
        }

        return ['resolved'=>$resolved,'blockers'=>array_values(array_unique($blockers)),'rows'=>$rows];
    }
}
