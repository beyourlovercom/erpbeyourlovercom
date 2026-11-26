<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\OrderItem;
use App\Modules\Products\Application\OutboundOrderSkuResolver;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductWarehouseSku;

/** One warehouse-specific SKU contract shared by allocation and every outbound path. */
final class WarehouseFinalSkuResolver
{
    public function __construct(private readonly OutboundOrderSkuResolver $legacyCanonicalSkus) {}

    public function mapped(int $productId, int $warehouseId): ?string
    {
        if ($productId < 1 || $warehouseId < 1) {
            return null;
        }

        $mapping = ProductWarehouseSku::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('is_active', true)
            ->whereNotNull('verified_at')
            ->first();

        $sku = trim((string) $mapping?->sku);

        return $sku !== '' ? $sku : null;
    }

    /**
     * FACTORY (including OUTSOURCE received/fulfilled there) uses the selected
     * active Product OS SKU when no explicit factory override exists. There is
     * no independent warehouse SKU catalog for a future procurement. Never
     * use the caller's old SKU, gift placeholder or another warehouse's alias.
     * Every external warehouse still requires its own verified mapping.
     */
    public function expected(int $productId, int $warehouseId): ?string
    {
        $mapped = $this->mapped($productId, $warehouseId);
        if ($mapped !== null || ! config('warehouse_final_sku.require_verified_mapping')) {
            return $mapped;
        }

        $warehouse = Warehouse::query()->whereKey($warehouseId)->where('is_active', true)->first();
        if (strtoupper(trim((string) $warehouse?->code)) !== 'FACTORY') {
            return null;
        }

        $sku = trim((string) Product::query()->whereKey($productId)->where('is_active', true)->value('sku'));

        return $sku !== '' && strcasecmp($sku, 'gift') !== 0 ? $sku : null;
    }

    public function resolve(int $productId, string $warehouseCode, string $fallbackSku): string
    {
        $code = strtoupper(trim($warehouseCode));
        $warehouse = Warehouse::query()->whereRaw('UPPER(code) = ?', [$code])->first();
        abort_unless($warehouse, 422, "找不到发货仓 {$warehouseCode}");

        $mapped = $this->expected($productId, (int) $warehouse->getKey());
        if ($mapped !== null) {
            return $mapped;
        }

        abort_if((bool) config('warehouse_final_sku.require_verified_mapping'), 422,
            "产品 #{$productId} 在 {$code} 仓库没有已校验的最终 SKU 映射，禁止发货");

        return trim($fallbackSku);
    }

    /**
     * Export / OMS paths may never re-canonicalize an already selected
     * physical SKU. The persisted order-line final_sku is authoritative.
     */
    public function forShipment(Shipment $shipment, OrderItem $item, bool $legacyCanonical = false): string
    {
        $final = trim((string) $item->final_sku);
        $legacy = trim((string) ($item->resolved_fulfillment_sku ?: $item->sku));
        $sku = $final !== '' ? $final : $legacy;
        abort_if($sku === '' || strcasecmp($sku, 'gift') === 0, 422, '订单最终发货 SKU 缺失，禁止发货');

        if ((bool) config('warehouse_final_sku.require_verified_mapping')) {
            $warehouseId = (int) $shipment->warehouse_id;
            $mapped = $this->expected((int) $item->product_id, $warehouseId);
            abort_unless($mapped !== null && $final !== '' && strcasecmp($mapped, $final) === 0, 422,
                '订单最终 SKU 与已校验的发货仓 SKU 映射不一致，请重新分仓或校对 SKU');
        }

        // Compatibility for already-existing provider files and MorePlus OMS
        // canary rows without final_sku. Once final_sku is assigned, there is
        // never a second canonicalization or alias substitution.
        if ($final === '' && $legacyCanonical) {
            return $this->legacyCanonicalSkus->canonical($sku, (int) $item->product_id);
        }

        return $sku;
    }
}
