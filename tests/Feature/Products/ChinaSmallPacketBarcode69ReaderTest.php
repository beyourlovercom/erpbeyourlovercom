<?php

namespace Tests\Feature\Products;

use App\Modules\Logistics\Application\Domestic\ChinaSmallPacketBarcode69Reader;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderItem;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ChinaSmallPacketBarcode69ReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_verified_product_barcode_as_string(): void
    {
        $product = Product::create([
            'sku' => 'VX357A', 'name' => 'Test', 'product_type' => 'simple',
            'source_type' => 'manual', 'barcode_69' => '6977291390537',
        ]);
        $order = Order::factory()->create();
        OrderItem::query()->create([
            'order_id' => $order->id, 'product_id' => $product->id, 'sku' => 'VX357A',
            'quantity' => 2, 'unit_price' => 0, 'line_revenue' => 0,
        ]);
        $this->assertSame([[
            'sku' => 'VX357A', 'quantity' => 2, 'barcode_69' => '6977291390537',
        ]], app(ChinaSmallPacketBarcode69Reader::class)->forOrder($order));
    }

    public function test_refuses_product_id_with_different_fulfillment_sku(): void
    {
        $product = Product::create([
            'sku' => 'VX357A', 'name' => 'Test', 'product_type' => 'simple',
            'source_type' => 'manual', 'barcode_69' => '6977291390537',
        ]);
        $order = Order::factory()->create();
        OrderItem::query()->create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'sku' => 'VX357A', 'resolved_fulfillment_sku' => 'VX357B',
            'quantity' => 1, 'unit_price' => 0, 'line_revenue' => 0,
        ]);
        $this->expectException(ValidationException::class);
        app(ChinaSmallPacketBarcode69Reader::class)->forOrder($order);
    }
}
