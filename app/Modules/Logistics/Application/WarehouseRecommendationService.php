<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use Illuminate\Support\Collection;

final class WarehouseRecommendationService
{
    public function __construct(
        private readonly InventoryAvailabilityReader $inventory,
        private readonly WarehouseRoutingPolicy $routing,
        private readonly UspsZoneResolver $zones,
    ) {}

    /** @param array<int, array{product_id:int|null,quantity:int}> $items */
    public function recommend(?string $country, array $items, ?string $postalCode = null): array
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

        $strategy = $this->routing->strategy($countryCode);
        if ($strategy === 'fixed') {
            return $this->recommendFixedWarehouse($countryCode, $requirements, $warehouses);
        }
        if ($strategy === 'east_zone_then_priority') {
            return $this->recommendEastZoneThenPriority($countryCode, $postalCode, $requirements, $warehouses);
        }

        return $this->recommendServiceRegion($countryCode, $requirements, $warehouses);
    }

    private function recommendFixedWarehouse(
        string $countryCode,
        Collection $requirements,
        Collection $warehouses,
    ): array {
        $fixedCode = $this->routing->fixedWarehouseCode($countryCode);
        if ($fixedCode === null) {
            return $this->blocked('fixed_warehouse_unconfigured', '固定仓策略未配置唯一仓库。', $countryCode);
        }

        $warehouse = $warehouses->first(fn (array $candidate): bool =>
            $this->routing->canonicalWarehouseCode((string) ($candidate['code'] ?? '')) === $fixedCode
        );
        if ($warehouse === null) {
            return $this->blocked('no_inventory_authority', "固定仓 {$fixedCode} 当前没有 Full Snapshot 权威库存。", $countryCode);
        }

        $region = collect($warehouse['service_regions'] ?? [])->first(
            fn (array $item): bool => strtoupper((string) ($item['country_code'] ?? '')) === $countryCode
        );
        if ($region === null) {
            return $this->blocked('no_service_region_match', "固定仓 {$fixedCode} 尚未配置 {$countryCode} 服务区域。", $countryCode);
        }

        $availability = $this->inventory->forProductIds($requirements->keys()->all());
        if (! $this->covers($warehouse, $requirements, $availability)) {
            return $this->blocked('insufficient_stock', "固定仓 {$fixedCode} 无法一次满足订单全部商品数量。", $countryCode);
        }

        return $this->recommended(
            $warehouse,
            $countryCode,
            $requirements->count(),
            1,
            'recommended_fixed_warehouse',
            "{$countryCode} 固定由 {$fixedCode} 履约，且库存可整单满足。",
        );
    }

    private function recommendEastZoneThenPriority(
        string $countryCode,
        ?string $postalCode,
        Collection $requirements,
        Collection $warehouses,
    ): array {
        $allowedCodes = $this->routing->warehouseCodes($countryCode);
        $policyWarehouses = $warehouses->filter(function (array $warehouse) use ($allowedCodes): bool {
            $canonical = $this->routing->canonicalWarehouseCode((string) ($warehouse['code'] ?? ''));
            return $canonical !== null && in_array($canonical, $allowedCodes, true);
        })->values();

        if ($policyWarehouses->isEmpty()) {
            return $this->blocked('no_inventory_authority', '路由策略中的仓库当前没有可用 Full Snapshot 权威库存。', $countryCode);
        }

        $availability = $this->inventory->forProductIds($requirements->keys()->all());
        $eligible = $policyWarehouses->filter(
            fn (array $warehouse): bool => $this->covers($warehouse, $requirements, $availability)
        )->values();
        if ($eligible->isEmpty()) {
            return $this->blocked('insufficient_stock', '美国候选仓无法由单一仓库一次满足订单全部商品数量。', $countryCode);
        }

        $eastRule = $this->routing->eastZoneRule($countryCode) ?? [];
        $eastCode = strtoupper(trim((string) ($eastRule['warehouse'] ?? '')));
        $maxZone = max(1, (int) ($eastRule['max_zone'] ?? 4));
        $dataFile = (string) ($eastRule['data_file'] ?? '');
        $zone = $this->zones->resolve($postalCode, $dataFile);

        $east = $eligible->first(fn (array $warehouse): bool =>
            $this->routing->canonicalWarehouseCode((string) ($warehouse['code'] ?? '')) === $eastCode
        );

        if ($east !== null && ($zone['status'] ?? null) === 'matched' && (int) $zone['zone'] <= $maxZone) {
            return $this->recommended(
                $east,
                $countryCode,
                $requirements->count(),
                $eligible->count(),
                'recommended_east_zone',
                "美东 USPS Zone {$zone['zone']} ≤ {$maxZone}，且美东仓可整单履约，优先美东仓。",
                $this->routingContext($postalCode, $zone, $this->routing->fallbackPriority($countryCode)),
            );
        }

        foreach ($this->routing->fallbackPriority($countryCode) as $code) {
            $winner = $eligible->first(fn (array $warehouse): bool =>
                $this->routing->canonicalWarehouseCode((string) ($warehouse['code'] ?? '')) === $code
            );
            if ($winner !== null) {
                $label = ($zone['status'] ?? null) === 'matched'
                    ? "美东 USPS Zone {$zone['zone']} 未触发 Zone ≤ {$maxZone} 美东优先，按 US-SJ → US-LA → US-NY 顺序选择可整单履约仓。"
                    : 'ZIP 无法从当前美东 USPS 分区表唯一确认 Zone，按 US-SJ → US-LA → US-NY 安全 fallback。';
                return $this->recommended(
                    $winner,
                    $countryCode,
                    $requirements->count(),
                    $eligible->count(),
                    'recommended_fallback_priority',
                    $label,
                    $this->routingContext($postalCode, $zone, $this->routing->fallbackPriority($countryCode)),
                );
            }
        }

        $winner = $eligible->sortBy([['priority', 'asc'], ['id', 'asc']])->first();
        return $this->recommended(
            $winner,
            $countryCode,
            $requirements->count(),
            $eligible->count(),
            'recommended_policy_safety_fallback',
            '路由优先级未覆盖全部候选仓，已按仓库主数据优先级安全兜底。',
            $this->routingContext($postalCode, $zone, $this->routing->fallbackPriority($countryCode)),
        );
    }

    private function recommendServiceRegion(string $countryCode, Collection $requirements, Collection $warehouses): array
    {
        if (! $warehouses->contains(fn (array $warehouse): bool => ! empty($warehouse['service_regions']))) {
            return $this->blocked('service_regions_unconfigured', '可自动分配仓库尚未配置服务国家/地区。');
        }

        $regional = $warehouses->map(function (array $warehouse) use ($countryCode): ?array {
            $region = collect($warehouse['service_regions'] ?? [])->first(
                fn (array $item): bool => strtoupper((string) ($item['country_code'] ?? '')) === $countryCode
            );
            return $region === null ? null : [...$warehouse, 'service_region_priority' => (int) ($region['priority'] ?? 9999)];
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

        return $this->recommended(
            $winner,
            $countryCode,
            $requirements->count(),
            $eligible->count(),
            'recommended',
            '库存与服务区域均满足，可采用系统推荐。',
        );
    }

    /** @param array<int, array{product_id:int|null,quantity:int}> $items */
    private function requirements(array $items): ?Collection
    {
        $requirements = collect();
        foreach ($items as $item) {
            $productId = $item['product_id'] ?? null;
            if ($productId === null) return null;
            $quantity = max(0, (int) ($item['quantity'] ?? 0));
            if ($quantity === 0) continue;
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
            if ((int) ($row['quantity_available'] ?? 0) < (int) $requiredQuantity) return false;
        }
        return true;
    }

    private function recommended(
        array $warehouse,
        string $countryCode,
        int $requiredSkuCount,
        int $candidateCount,
        string $reasonCode,
        string $reasonLabel,
        ?array $routingContext = null,
    ): array {
        $canonical = $this->routing->canonicalWarehouseCode((string) ($warehouse['code'] ?? ''));
        return [
            'status' => 'recommended',
            'reason_code' => $reasonCode,
            'reason_label' => $reasonLabel,
            'country_code' => $countryCode,
            'required_sku_count' => $requiredSkuCount,
            'candidate_count' => $candidateCount,
            'warehouse' => [
                'id' => (int) $warehouse['id'],
                'slug' => (string) $warehouse['slug'],
                'code' => $canonical ?? (string) $warehouse['code'],
                'name' => (string) $warehouse['name'],
            ],
            'routing_context' => $routingContext,
        ];
    }

    private function routingContext(?string $postalCode, array $zone, array $fallback): array
    {
        return [
            'strategy' => 'east_zone_then_priority',
            'postal_code' => $postalCode,
            'east_zone_status' => $zone['status'] ?? null,
            'east_zone' => $zone['zone'] ?? null,
            'east_zone_prefix' => $zone['prefix'] ?? null,
            'fallback_priority' => $fallback,
        ];
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
            'routing_context' => null,
        ];
    }
}
