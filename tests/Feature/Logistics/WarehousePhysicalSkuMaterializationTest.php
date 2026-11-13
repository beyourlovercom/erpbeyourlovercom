<?php

namespace Tests\Feature\Logistics;

use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Application\FulfillmentAllocationSubmissionService;
use App\Modules\Logistics\Application\MorePlusPhysicalStockReader;
use App\Modules\Logistics\Infrastructure\Models\WarehouseGiftSkuMapping;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Application\SkuMatchKey;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductSkuAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class WarehousePhysicalSkuMaterializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_warehouse_gift_materializes_actual_sku_and_source_product_identity(): void
    {
        [$user, $order, $item, $gift, $mapping] = $this->giftScenario();
        app(FulfillmentAllocationSubmissionService::class)->submitManual($order, [
            ['order_item_id' => $item->id, 'source_code' => 'US-SM', 'gift_mapping_id' => $mapping->id],
        ], 'selected gift', $user);

        $actual = $item->fresh();
        self::assertSame($gift->sku, $actual->sku);
        self::assertSame($gift->id, $actual->product_id);
        self::assertSame('gift', $actual->source_sku);
        self::assertSame($item->product_id, $actual->source_product_id);
        self::assertSame($gift->sku, $actual->resolved_fulfillment_sku);
        self::assertSame('0.00', $actual->line_revenue);
        self::assertSame('US-SM', $order->fresh()->shipment->warehouse->code);
    }

    public function test_reselecting_a_gift_replaces_outbound_identity_without_destroying_source(): void
    {
        [$user, $order, $item, $gift, $mapping, $warehouse] = $this->giftScenario();
        $service = app(FulfillmentAllocationSubmissionService::class);
        $assignment = fn (int $mappingId): array => [
            ['order_item_id' => $item->id, 'source_code' => 'US-SM', 'gift_mapping_id' => $mappingId],
        ];
        $service->submitManual($order, $assignment($mapping->id), null, $user);
        $other = $this->product('SM-NEW-GIFT', 'Other gift');
        $this->inventory($warehouse, $other, 9);
        $next = WarehouseGiftSkuMapping::create([
            'warehouse_id' => $warehouse->id, 'source_sku' => 'gift',
            'fulfillment_product_id' => $other->id, 'priority' => 2, 'is_active' => true,
        ]);
        $service->submitManual($order->fresh(['items', 'address']), $assignment($next->id), null, $user);
        $actual = $item->fresh();
        self::assertSame($other->sku, $actual->sku);
        self::assertSame($other->id, $actual->product_id);
        self::assertSame('gift', $actual->source_sku);
        self::assertSame($item->product_id, $actual->source_product_id);
        self::assertSame($other->sku, $actual->resolved_fulfillment_sku);
    }

    public function test_moreplus_alias_materializes_only_physical_verified_provider_sku(): void
    {
        [$user, $order, $item, $product, $warehouse] = $this->ordinaryScenario();
        ProductSkuAlias::query()->forceCreate([
            'product_id' => $product->id, 'alias_sku' => 'BY489A',
            'match_key' => SkuMatchKey::compute('BY489A'), 'source_type' => 'manual',
            'is_active' => true, 'oms_verified_at' => now(),
            'oms_identity_evidence' => 'Physically verified warehouse-lube equivalent 2026-11-13',
        ]);
        config()->set('shipout.production_read_enabled', true);
        config()->set('shipout.production_warehouse_id', '1265864414750314497');
        config()->set('shipout.production_verified_alias_pairs', 'BY489A-Lubes=>BY489A');
        $this->mock(MorePlusPhysicalStockReader::class)->shouldReceive('snapshot')->once()->andReturn([
            'warehouse_id' => '1265864414750314497',
            'stock_rows' => [[
                'provider_sku' => 'BY489A', 'available_quantity' => 8,
                'in_product_catalog' => true, 'product_status' => '1', 'sku_alias_status' => 'verified',
            ]],
        ]);
        app(FulfillmentAllocationSubmissionService::class)->submitManual($order, [
            ['order_item_id' => $item->id, 'source_code' => 'US-LA'],
        ], null, $user);
        $actual = $item->fresh();
        self::assertSame('BY489A', $actual->sku);
        self::assertSame('BY489A-Lubes', $actual->source_sku);
        self::assertSame($product->id, $actual->product_id);
        self::assertSame($product->id, $actual->source_product_id);
    }

    public function test_moreplus_missing_physical_sku_fails_closed_and_keeps_order_unchanged(): void
    {
        [$user, $order, $item] = $this->ordinaryScenario();
        config()->set('shipout.production_read_enabled', true);
        config()->set('shipout.production_warehouse_id', '1265864414750314497');
        $this->mock(MorePlusPhysicalStockReader::class)->shouldReceive('snapshot')->once()->andReturn([
            'warehouse_id' => '1265864414750314497', 'stock_rows' => [],
        ]);
        try {
            app(FulfillmentAllocationSubmissionService::class)->submitManual($order, [
                ['order_item_id' => $item->id, 'source_code' => 'US-LA'],
            ], null, $user);
            self::fail('Expected hard fail for provider physical SKU');
        } catch (HttpException $error) {
            self::assertSame(422, $error->getStatusCode());
        }
        self::assertSame('BY489A-Lubes', $item->fresh()->sku);
        self::assertNull($item->fresh()->source_product_id);
        self::assertNull($order->fresh()->shipment);
    }

    private function giftScenario(): array
    {
        $user = $this->operator();
        $warehouse = $this->warehouse('US-SM');
        $order = $this->order();
        $source = $this->product('SOURCE-GIFT', 'Gift source');
        $item = $order->items()->create([
            'product_id' => $source->id, 'sku' => 'gift', 'product_summary' => 'Random Gift',
            'quantity' => 1, 'unit_price' => '0.00', 'line_revenue' => '0.00',
        ]);
        $gift = $this->product('ACTUAL-GIFT-SM', 'Selected gift');
        $this->inventory($warehouse, $gift, 8);
        $mapping = WarehouseGiftSkuMapping::create([
            'warehouse_id' => $warehouse->id, 'source_sku' => 'gift',
            'fulfillment_product_id' => $gift->id, 'priority' => 1, 'is_active' => true,
        ]);

        return [$user, $order, $item, $gift, $mapping, $warehouse];
    }

    private function ordinaryScenario(): array
    {
        $user = $this->operator();
        $warehouse = $this->warehouse('US-LA');
        $order = $this->order();
        $product = $this->product('BY489A-Lubes', 'Lubricant 35ml');
        $item = $order->items()->create([
            'product_id' => $product->id, 'sku' => $product->sku,
            'product_summary' => $product->name, 'quantity' => 1,
            'unit_price' => '12.00', 'line_revenue' => '12.00',
        ]);
        $this->inventory($warehouse, $product, 9);

        return [$user, $order, $item, $product, $warehouse];
    }

    private function order(): Order
    {
        $order = Order::create([
            'internal_order_number' => 'SKU-'.uniqid(), 'erp_order_id' => '007-SKU-'.uniqid(),
            'source_type' => 'manual', 'order_kind' => 'customer', 'external_order_number' => 'EXT-'.uniqid(),
            'order_date' => '2026-11-13', 'order_status' => 'pending_shipment', 'currency' => 'USD',
        ]);
        $order->address()->create([
            'recipient_name' => 'Test Recipient', 'phone' => '10000000000', 'country' => 'US',
            'province_state' => 'CA', 'city' => 'Los Angeles',
            'address_line_1' => '123 Test Avenue', 'postal_code' => '90001',
        ]);

        return $order;
    }

    private function product(string $sku, string $name): Product
    {
        return Product::create([
            'sku' => $sku, 'name' => $name,
            'product_type' => 'simple', 'source_type' => 'manual', 'is_active' => true,
        ]);
    }

    private function warehouse(string $code): Warehouse
    {
        return Warehouse::firstOrCreate(['code' => $code], [
            'slug' => strtolower($code).'-test', 'name' => $code.' Warehouse',
            'country_code' => 'US', 'is_active' => true, 'priority' => 10,
        ]);
    }

    private function inventory(Warehouse $warehouse, Product $product, int $qty): void
    {
        WarehouseInventory::create([
            'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
            'quantity_on_hand' => $qty, 'quantity_reserved' => 0,
        ]);
    }

    private function operator(): User
    {
        $role = Role::create(['key' => 'physical-sku-'.uniqid(), 'label' => 'Physical SKU']);
        foreach (['logistics.view', 'logistics.operate'] as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
