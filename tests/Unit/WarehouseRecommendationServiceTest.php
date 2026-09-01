<?php

namespace Tests\Unit;

use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use App\Modules\Logistics\Application\WarehouseRecommendationService;
use PHPUnit\Framework\TestCase;

final class WarehouseRecommendationServiceTest extends TestCase
{
    public function test_recommends_country_matched_full_snapshot_warehouse_with_stock(): void
    {
        $service = new WarehouseRecommendationService($this->reader(
            [$this->warehouse(2, 'US-NY', true, [['country_code' => 'US', 'priority' => 1]])],
            [10 => [$this->stock(2, 8)]],
        ));

        $result = $service->recommend('us', [['product_id' => 10, 'quantity' => 3]]);

        self::assertSame('recommended', $result['status']);
        self::assertSame(2, $result['warehouse']['id']);
        self::assertSame('US', $result['country_code']);
    }

    public function test_blocks_when_full_snapshot_warehouses_have_no_service_regions(): void
    {
        $service = new WarehouseRecommendationService($this->reader([$this->warehouse(2, 'US-NY', true, [])], []));
        $result = $service->recommend('US', [['product_id' => 10, 'quantity' => 1]]);
        self::assertSame('service_regions_unconfigured', $result['reason_code']);
    }

    public function test_blocks_when_matching_region_cannot_cover_all_quantity(): void
    {
        $service = new WarehouseRecommendationService($this->reader(
            [$this->warehouse(2, 'US-NY', true, [['country_code' => 'US', 'priority' => 1]])],
            [10 => [$this->stock(2, 2)]],
        ));

        $result = $service->recommend('US', [['product_id' => 10, 'quantity' => 3]]);
        self::assertSame('insufficient_stock', $result['reason_code']);
    }

    public function test_duplicate_product_lines_are_aggregated_before_stock_check(): void
    {
        $service = new WarehouseRecommendationService($this->reader(
            [$this->warehouse(2, 'US-NY', true, [['country_code' => 'US', 'priority' => 1]])],
            [10 => [$this->stock(2, 4)]],
        ));

        $result = $service->recommend('US', [
            ['product_id' => 10, 'quantity' => 2],
            ['product_id' => 10, 'quantity' => 3],
        ]);
        self::assertSame('insufficient_stock', $result['reason_code']);
    }

    public function test_service_region_priority_wins_before_warehouse_priority(): void
    {
        $warehouses = [
            $this->warehouse(2, 'US-NY', true, [['country_code' => 'US', 'priority' => 2]], 10),
            $this->warehouse(3, 'DE', true, [['country_code' => 'US', 'priority' => 1]], 30),
        ];
        $availability = [10 => [$this->stock(2, 10), $this->stock(3, 10)]];
        $result = (new WarehouseRecommendationService($this->reader($warehouses, $availability)))
            ->recommend('US', [['product_id' => 10, 'quantity' => 1]]);

        self::assertSame(3, $result['warehouse']['id']);
    }

    public function test_unconfirmed_warehouse_is_never_considered(): void
    {
        $service = new WarehouseRecommendationService($this->reader(
            [$this->warehouse(2, 'US-NY', false, [['country_code' => 'US', 'priority' => 1]])],
            [10 => [$this->stock(2, 100)]],
        ));
        $result = $service->recommend('US', [['product_id' => 10, 'quantity' => 1]]);
        self::assertSame('no_inventory_authority', $result['reason_code']);
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
