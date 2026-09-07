<?php

namespace Tests\Unit;

use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use App\Modules\Logistics\Application\UspsZoneResolver;
use App\Modules\Logistics\Application\WarehouseRecommendationService;
use App\Modules\Logistics\Application\WarehouseRoutingPolicy;
use Tests\TestCase;

final class WarehouseRecommendationServiceTest extends TestCase
{
    public function test_generic_service_region_recommendation_still_works(): void
    {
        $service = $this->service(
            [$this->warehouse(2, 'W-GB', true, [['country_code' => 'GB', 'priority' => 1]])],
            [10 => [$this->stock(2, 8)]],
        );
        $result = $service->recommend('gb', [['product_id' => 10, 'quantity' => 3]]);
        $this->assertSame('recommended', $result['status']);
        $this->assertSame(2, $result['warehouse']['id']);
    }

    public function test_generic_route_blocks_without_service_regions(): void
    {
        $result = $this->service([$this->warehouse(2, 'W-GB', true, [])], [])
            ->recommend('GB', [['product_id' => 10, 'quantity' => 1]]);
        $this->assertSame('service_regions_unconfigured', $result['reason_code']);
    }

    public function test_us_zone_four_or_less_prefers_us_ny_when_all_have_stock(): void
    {
        $warehouses = [
            $this->warehouse(1, 'US-LA', true, []),
            $this->warehouse(2, 'US-NY', true, []),
            $this->warehouse(7, 'US-SJ', true, []),
        ];
        $availability = [10 => [$this->stock(1, 10), $this->stock(2, 10), $this->stock(7, 10)]];
        $result = $this->service($warehouses, $availability)
            ->recommend('US', [['product_id' => 10, 'quantity' => 1]], '10001');

        $this->assertSame('US-NY', $result['warehouse']['code']);
        $this->assertSame('recommended_east_zone', $result['reason_code']);
        $this->assertSame(1, $result['routing_context']['east_zone']);
    }

    public function test_us_zone_over_four_prefers_us_sj_then_us_la_then_us_ny(): void
    {
        $warehouses = [
            $this->warehouse(1, 'US-LA', true, []),
            $this->warehouse(2, 'US-NY', true, []),
            $this->warehouse(7, 'US-SJ', true, []),
        ];
        $availability = [10 => [$this->stock(1, 10), $this->stock(2, 10), $this->stock(7, 10)]];
        $result = $this->service($warehouses, $availability)
            ->recommend('US', [['product_id' => 10, 'quantity' => 1]], '90001');

        $this->assertSame('US-SJ', $result['warehouse']['code']);
        $this->assertSame(8, $result['routing_context']['east_zone']);
    }

    public function test_us_falls_to_us_la_when_us_sj_cannot_cover_whole_order(): void
    {
        $warehouses = [
            $this->warehouse(1, 'US-LA', true, []),
            $this->warehouse(2, 'US-NY', true, []),
            $this->warehouse(7, 'US-SJ', true, []),
        ];
        $availability = [10 => [$this->stock(1, 10), $this->stock(2, 10), $this->stock(7, 0)]];
        $result = $this->service($warehouses, $availability)
            ->recommend('US', [['product_id' => 10, 'quantity' => 1]], '90001');
        $this->assertSame('US-LA', $result['warehouse']['code']);
    }

    public function test_us_unknown_or_conflicting_zone_uses_safe_fallback_not_east_priority(): void
    {
        $warehouses = [
            $this->warehouse(2, 'US-NY', true, []),
            $this->warehouse(7, 'US-SJ', true, []),
        ];
        $availability = [10 => [$this->stock(2, 10), $this->stock(7, 10)]];
        $result = $this->service($warehouses, $availability)
            ->recommend('US', [['product_id' => 10, 'quantity' => 1]], '37601');

        $this->assertSame('US-SJ', $result['warehouse']['code']);
        $this->assertSame('ambiguous_source_ranges', $result['routing_context']['east_zone_status']);
        $this->assertNull($result['routing_context']['east_zone']);
    }

    public function test_us_only_considers_whole_order_stock(): void
    {
        $warehouses = [
            $this->warehouse(1, 'US-LA', true, []),
            $this->warehouse(2, 'US-NY', true, []),
            $this->warehouse(7, 'US-SJ', true, []),
        ];
        $availability = [
            10 => [$this->stock(1, 5), $this->stock(2, 0), $this->stock(7, 5)],
            20 => [$this->stock(1, 0), $this->stock(2, 5), $this->stock(7, 5)],
        ];
        $result = $this->service($warehouses, $availability)->recommend('US', [
            ['product_id' => 10, 'quantity' => 1],
            ['product_id' => 20, 'quantity' => 1],
        ], '10001');
        $this->assertSame('US-SJ', $result['warehouse']['code']);
        $this->assertSame(1, $result['candidate_count']);
    }

    public function test_unconfirmed_warehouse_is_never_considered(): void
    {
        $result = $this->service([$this->warehouse(2, 'US-NY', false, [])], [10 => [$this->stock(2, 100)]])
            ->recommend('US', [['product_id' => 10, 'quantity' => 1]], '10001');
        $this->assertSame('no_inventory_authority', $result['reason_code']);
    }

    private function service(array $warehouses, array $availability): WarehouseRecommendationService
    {
        return new WarehouseRecommendationService(
            $this->reader($warehouses, $availability),
            app(WarehouseRoutingPolicy::class),
            app(UspsZoneResolver::class),
        );
    }

    private function warehouse(int $id, string $code, bool $auto, array $regions, int $priority = 20): array
    {
        return [
            'id' => $id, 'slug' => strtolower($code), 'name' => $code, 'code' => $code,
            'country_code' => null, 'priority' => $priority, 'can_auto_allocate' => $auto,
            'service_regions' => $regions,
        ];
    }

    private function stock(int $warehouseId, int $available): array
    {
        return [
            'warehouse_id' => $warehouseId, 'warehouse_slug' => 'w-'.$warehouseId,
            'warehouse_name' => 'W'.$warehouseId, 'quantity_on_hand' => $available,
            'quantity_reserved' => 0, 'quantity_available' => $available,
        ];
    }

    private function reader(array $warehouses, array $availability): InventoryAvailabilityReader
    {
        return new class($warehouses, $availability) implements InventoryAvailabilityReader
        {
            public function __construct(private array $warehouses, private array $availability) {}
            public function activeWarehouses(): array { return $this->warehouses; }
            public function forProductIds(array $productIds): array
            {
                return collect($this->availability)->only($productIds)->all();
            }
        };
    }
}
