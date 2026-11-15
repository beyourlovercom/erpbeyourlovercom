<?php

namespace Tests\Unit;

use App\Modules\Logistics\Application\WarehouseSkuPresentation;
use App\Modules\Orders\Infrastructure\Models\OrderItem;
use PHPUnit\Framework\TestCase;

final class WarehouseSkuPresentationTest extends TestCase
{
    public function test_real_warehouse_sku_is_displayed_not_source_placeholder(): void
    {
        $actual = new OrderItem(['sku' => 'VX610A', 'source_sku' => 'gift',
            'resolved_fulfillment_sku' => 'VX610A']);
        self::assertSame('VX610A', WarehouseSkuPresentation::sku($actual));
        self::assertTrue(WarehouseSkuPresentation::verified($actual));
    }

    public function test_unproven_historical_gift_is_flagged_not_falsely_relabelled(): void
    {
        $legacy = new OrderItem(['sku' => 'gift']);
        self::assertSame('实际礼品SKU未登记', WarehouseSkuPresentation::sku($legacy));
        self::assertFalse(WarehouseSkuPresentation::verified($legacy));
    }

    public function test_mismatch_cannot_be_claimed_as_verified(): void
    {
        $mismatch = new OrderItem(['sku' => 'gift',
            'resolved_fulfillment_sku' => 'VX610A']);
        self::assertSame('实际礼品SKU未登记', WarehouseSkuPresentation::sku($mismatch));
        self::assertFalse(WarehouseSkuPresentation::verified($mismatch));
    }
}
