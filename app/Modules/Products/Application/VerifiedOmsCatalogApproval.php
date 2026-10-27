<?php

namespace App\Modules\Products\Application;

/**
 * Strict OMS product approval parser. Used only AFTER the page parser has
 * proved a complete account catalog. Vendor records need not carry whCode:
 * warehouse scope comes separately from integratedInventory/pageOpen.
 * No permissive fallback for absent approveStatus.
 */
final class VerifiedOmsCatalogApproval
{
    /**
     * @param list<array<string,mixed>> $completeCatalogRows Trusted all-page result, not a page-1 subset
     * @param list<string> $requiredSkus Exact warehouse snapshot candidate codes
     * @return array{status:string,code:string,approved_skus:list<string>}
     */
    public function check(array $completeCatalogRows, array $requiredSkus, bool $paginationComplete): array
    {
        $block = static fn (string $reason): array => ['status'=>'blocked','code'=>$reason,'approved_skus'=>[]];
        if (! $paginationComplete || $completeCatalogRows === [] || $requiredSkus === []) {
            return $block('OMS_PRODUCT_CATALOG_INCOMPLETE');
        }
        $catalog = [];
        foreach ($completeCatalogRows as $row) {
            $sku = trim((string)($row['sku'] ?? ''));
            $key = SkuMatchKey::compute($sku);
            if ($key === null || array_key_exists($key, $catalog)) {
                return $block('OMS_PRODUCT_CATALOG_SKU_CONFLICT');
            }
            $catalog[$key] = ['sku'=>$sku, 'approval'=>$row['approveStatus'] ?? null];
        }
        $approved = [];
        foreach ($requiredSkus as $sku) {
            $key = SkuMatchKey::compute($sku);
            if ($key === null || ! isset($catalog[$key])) return $block('OMS_PRODUCT_SKU_MISSING');
            if ($catalog[$key]['sku'] !== $sku) return $block('OMS_PRODUCT_SKU_CASE_MISMATCH');
            $status = $catalog[$key]['approval'];
            if (! ((is_int($status) && $status === 2) || (is_string($status) && $status === '2'))) {
                return $block('OMS_PRODUCT_SKU_NOT_EXPLICITLY_APPROVED');
            }
            if (! in_array($sku, $approved, true)) $approved[] = $sku;
        }
        return ['status'=>'ready','code'=>'OMS_PRODUCT_CATALOG_VERIFIED','approved_skus'=>$approved];
    }
}
