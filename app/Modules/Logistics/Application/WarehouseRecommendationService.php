<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use Illuminate\Support\Collection;

final class WarehouseRecommendationService
{
    public function __construct(private readonly InventoryAvailabilityReader $inventory) {}

    /** @param array<int, array{product_id:int|null,quantity:int}> $items */
    public function recommend(?string $country, array $items): array
    {
        $countryCode = strtoupper(trim((string) $country));
        if ($countryCode === '') {
            return $this->blocked('missing_country', '订单缺少国家代码，无法自动推荐仓库。');
        }
        if (! preg_match('/^[A-Z]{2}$/', $countryCode)) {
            return $this->blocked('invalid_country_code', '订单国家必须使用 ISO 2 位代码后才能自动推荐。');
        }

        $requirements = $this->requirements($items);
        if ($requirements === null) {
            return $this->blocked('unresolved_product', '订单存在未关联 Product Master 的商品，需人工处理。');
        }
        if ($requirements->isEmpty()) {
            return $this->blocked('no_items', '订单没有可用于库存判断的商品。');
        }
        $warehouses = collect($this->inventory->activeWarehouses())
            ->filter(fn (array $warehouse): bool => (bool) ($warehouse['can_auto_allocate'] ?? false));
        if ($warehouses->isEmpty()) {
            return $this->blocked('no_inventory_authority', '当前没有具备 Full Snapshot 权威库存的仓库。');
        }
        if (! $warehouses->contains(fn (array $warehouse): bool => ! empty($warehouse['service_regions']))) {
            return $this->blocked('service_regions_unconfigured', '可自动分配仓库尚未配置服务国家/地区。');
        }

        $regional = $warehouses->map(function (array $warehouse) use ($countryCode): ?array {
            $region = collect($warehouse['service_regions'] ?? [])->first(
                fn (array $item): bool => strtoupper((string) ($item['country_code'] ?? '')) === $countryCode
            );
            if ($region === null) {
                return null;
            }
            return [...$warehouse, 'service_region_priority' => (int) ($region['priority'] ?? 9999)];
        })->filter()->values();

        if ($regional->isEmpty()) {
            return $this->blocked('no_service_region_match', "没有可自动分配仓库覆盖 {$countryCode}。", $countryCode);
        }

        $availability = $this->inventory->forProductIds($requirements->keys()->all());
        $eligible = $regional->filter(
            fn (array $warehouse): bool => $this->covers($warehouse, $requirements, $availability)
        );
        if ($eligible->isEmpty()) {
            return $this->blocked('insufficient_stock', '覆盖该国家的仓库无法一次满足订单全部商品数量。', $countryCode);
        }

        $winner = $eligible->sortBy([
            ['service_region_priority', 'asc'],
            ['priority', 'asc'],
            ['id', 'asc'],
        ])->first();

        return [
            'status' => 'recommended',
            'reason_code' => 'recommended',
            'reason_label' => '库存与服务区域均满足，可采用系统推荐。',
            'country_code' => $countryCode,
            'required_sku_count' => $requirements->count(),
            'candidate_count' => $eligible->count(),
            'warehouse' => [
                'id' => (int) $winner['id'],
                'slug' => (string) $winner['slug'],
                'code' => (string) $winner['code'],
                'name' => (string) $winner['name'],
            ],
        ];
    }

    /** @param array<int, array{product_id:int|null,quantity:int}> $items */
    private function requirements(array $items): ?Collection
    {
        $requirements = collect();
        foreach ($items as $item) {
            $productId = $item['product_id'] ?? null;
            if ($productId === null) {
                return null;
            }
            $quantity = max(0, (int) ($item['quantity'] ?? 0));
            if ($quantity === 0) {
                continue;
            }
            $requirements[(int) $productId] = (int) ($requirements[(int) $productId] ?? 0) + $quantity;
        }
        return $requirements;
    }

    private function covers(array $warehouse, Collection $requirements, array $availability): bool
    {
        foreach ($requirements as $productId => $requiredQuantity) {
            $row = collect($availability[(int) $productId] ?? [])->first(
                fn (array $item): bool => (int) ($item['warehouse_id'] ?? 0) === (int) $warehouse['id']
            );
            if ((int) ($row['quantity_available'] ?? 0) < (int) $requiredQuantity) {
                return false;
            }
        }
        return true;
    }

    private function blocked(string $reasonCode, string $reasonLabel, ?string $countryCode = null): array
    {
        return [
            'status' => 'blocked',
            'reason_code' => $reasonCode,
            'reason_label' => $reasonLabel,
            'country_code' => $countryCode,
            'required_sku_count' => null,
            'candidate_count' => 0,
            'warehouse' => null,
        ];
    }
}
