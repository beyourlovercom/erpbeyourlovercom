<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Orders\Infrastructure\Models\OrderItem;
use App\Modules\Products\Application\SkuMatchKey;
use App\Modules\Products\Application\VerifiedOmsProductIdentityResolver;
use App\Modules\Products\Infrastructure\Models\ProductSkuAlias;

/**
 * Resolve the physical SKU before an order enters warehouse fulfillment.
 * Never infer an OMS SKU from a shared product ID alone.
 */
final class WarehousePhysicalSkuResolver
{
    private ?array $morePlusSnapshot = null;

    public function __construct(
        private readonly GiftSkuResolutionService $gifts,
        private readonly MorePlusPhysicalStockReader $morePlus,
        private readonly VerifiedOmsProductIdentityResolver $identity,
    ) {}

    /** @return array<string,mixed> */
    public function resolve(OrderItem $item, string $warehouseCode, ?int $giftMappingId): array
    {
        $sourceSku = trim((string) ($item->source_sku ?: $item->sku));
        $sourceProductId = (int) ($item->source_product_id ?: $item->product_id);
        abort_if($sourceSku === '' || $sourceProductId < 1, 422, '订单来源商品身份无效，不能分仓发货');

        $gift = $this->gifts->resolve($item, $warehouseCode, $giftMappingId);
        $targetId = (int) ($gift['resolved_product_id'] ?? $sourceProductId);
        $targetSku = trim((string) ($gift['resolved_fulfillment_sku'] ?? $sourceSku));

        if (strtoupper($warehouseCode) === 'US-LA' && config('shipout.production_read_enabled', false)) {
            [$targetId, $targetSku] = $this->morePlusPhysical($sourceSku, $targetId, $targetSku, (int) $item->quantity, $gift !== null);
        }

        return [
            'source_sku' => $sourceSku,
            'source_product_id' => $sourceProductId,
            'source_product_summary' => $item->source_product_summary ?: $item->product_summary,
            'gift_mapping_id' => $gift['gift_mapping_id'] ?? null,
            'resolved_product_id' => $targetId,
            'resolved_fulfillment_sku' => $targetSku,
            'resolved_product_summary' => $gift['resolved_product_summary'] ?? null,
        ];
    }

    /** @return array{int,string} */
    private function morePlusPhysical(string $sourceSku, int $productId, string $selectedSku, int $quantity, bool $gift): array
    {
        $snapshot = $this->morePlusSnapshot ??= $this->morePlus->snapshot();
        abort_unless((string) ($snapshot['warehouse_id'] ?? '') === trim((string) config('shipout.production_warehouse_id')), 422, 'MorePlus 仓库库存身份不匹配');
        $options = [];
        foreach ($snapshot['stock_rows'] ?? [] as $row) {
            if (! is_array($row) || ! ($row['in_product_catalog'] ?? false)
                || (string) ($row['product_status'] ?? '') !== '1'
                || ($row['sku_alias_status'] ?? '') !== 'verified') {
                continue;
            }
            $candidateSku = trim((string) ($row['provider_sku'] ?? ''));
            $verified = $this->identity->resolve($candidateSku);
            if (($verified['status'] ?? '') !== 'resolved' || (int) ($verified['product_id'] ?? 0) !== $productId) {
                continue;
            }
            if (! is_int($row['available_quantity'] ?? null)) {
                continue;
            }
            $options[] = ['sku' => $candidateSku, 'available' => $row['available_quantity']];
        }
        $exact = array_values(array_filter($options, fn (array $candidate): bool => strcasecmp($candidate['sku'], $selectedSku) === 0
        ));
        if (count($exact) > 1) {
            abort(422, 'MorePlus 实际 SKU 存在重复记录，禁止推仓');
        }
        if ($exact !== []) {
            $selected = $exact[0];
        } else {
            abort_if($gift, 422, "已选礼品 {$selectedSku} 不存在于 MorePlus 的有效库存与商品目录");
            $approved = array_values(array_filter($options, fn (array $candidate): bool => $this->approvedAlias($sourceSku, $candidate['sku'], $productId)
            ));
            abort_if(count($approved) !== 1, 422, count($approved) > 1
                ? "MorePlus 商品 {$sourceSku} 存在多个已批准发货 SKU，需人工确认"
                : "MorePlus 商品 {$sourceSku} 没有已验证的实际 SKU 映射");
            $selected = $approved[0];
        }
        abort_if($quantity < 1 || $selected['available'] < $quantity, 422, "MorePlus SKU {$selected['sku']} 库存不足");

        return [$productId, $selected['sku']];
    }

    private function approvedAlias(string $sourceSku, string $candidate, int $productId): bool
    {
        $key = SkuMatchKey::compute($candidate);
        $alias = $key ? ProductSkuAlias::query()
            ->where('product_id', $productId)->where('match_key', $key)
            ->where('is_active', true)->whereNotNull('oms_verified_at')
            ->whereNotNull('oms_dispatch_approved_at')
            ->whereNotNull('oms_identity_evidence')->first() : null;
        if ($alias && trim((string) $alias->oms_identity_evidence) !== ''
            && strcasecmp((string) $alias->oms_dispatch_canonical_sku, $sourceSku) === 0) {
            return true;
        }

        // Legacy exact pairs were individually approved; no wildcard inference.
        foreach (explode(',', (string) config('shipout.production_verified_alias_pairs', '')) as $pair) {
            $parts = array_map('trim', explode('=>', $pair, 2));
            if (count($parts) === 2 && strcasecmp($parts[0], $sourceSku) === 0
                && strcasecmp($parts[1], $candidate) === 0) {
                return true;
            }
        }

        return false;
    }
}
