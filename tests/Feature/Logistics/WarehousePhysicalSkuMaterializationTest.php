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

    public function test_active_erp_alias_materializes_canonical_provider_sku_without_env_pair(): void
    {
        [$user, $order, $item, $product] = $this->ordinaryScenario();
        $product->forceFill(['sku' => 'BY489A'])->save();
        ProductSkuAlias::query()->forceCreate([
            'product_id' => $product->id, 'alias_sku' => 'BY489A-Lubes',
            'match_key' => SkuMatchKey::compute('BY489A-Lubes'),
            'source_type' => 'woocommerce', 'is_active' => true,
        ]);
        config()->set('shipout.production_read_enabled', true);
        config()->set('shipout.production_warehouse_id', '1265864414750314497');
        config()->set('shipout.production_verified_alias_pairs', '');
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
        self::assertSame('BY489A', $item->fresh()->sku);
        self::assertSame('BY489A-Lubes', $item->fresh()->source_sku);
    }

    public function test_moreplus_missing_physical_sku_allows_explicit_manual_allocation_as_pending_draft(): void
    {
        [$user, $order, $item] = $this->ordinaryScenario();
        config()->set('shipout.production_read_enabled', true);
        config()->set('shipout.production_warehouse_id', '1265864414750314497');
        $this->mock(MorePlusPhysicalStockReader::class)->shouldReceive('snapshot')->once()->andReturn([
            'warehouse_id' => '1265864414750314497', 'stock_rows' => [],
        ]);
        $allocation = app(FulfillmentAllocationSubmissionService::class)->submitManual($order, [
            ['order_item_id' => $item->id, 'source_code' => 'US-LA'],
        ], null, $user);
        self::assertSame('submitted', $allocation->status);
        self::assertSame('BY489A-Lubes', $item->fresh()->sku);
        self::assertNotNull($item->fresh()->source_product_id);
        self::assertSame('US-LA', $order->fresh()->shipment?->warehouse?->code);
        self::assertSame('pending', $order->fresh()->shipment?->shipment_status);
        self::assertNull($order->fresh()->shipment?->tracking_number);
    }

    public function test_moreplus_oms_reader_error_does_not_block_manual_allocation(): void
    {
        [$user, $order, $item] = $this->ordinaryScenario();
        config()->set('shipout.production_read_enabled', true);
        $this->mock(MorePlusPhysicalStockReader::class)->shouldReceive('snapshot')->once()
            ->andThrow(new \RuntimeException('Synthetic OMS read outage'));
        $allocation = app(FulfillmentAllocationSubmissionService::class)->submitManual($order, [
            ['order_item_id' => $item->id, 'source_code' => 'US-LA'],
        ], 'operator decides without OMS', $user);

        self::assertSame('submitted', $allocation->status);
        self::assertSame('US-LA', $order->fresh()->shipment?->warehouse?->code);
        self::assertNull($order->fresh()->shipment?->tracking_number);
    }

    public function test_non_manual_moreplus_resolver_still_rejects_missing_provider_identity(): void
    {
        [, , $item] = $this->ordinaryScenario();
        config()->set('shipout.production_read_enabled', true);
        config()->set('shipout.production_warehouse_id', '1265864414750314497');
        $this->mock(MorePlusPhysicalStockReader::class)->shouldReceive('snapshot')->once()->andReturn([
            'warehouse_id' => '1265864414750314497', 'stock_rows' => [],
        ]);
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0);
        app(\App\Modules\Logistics\Application\WarehousePhysicalSkuResolver::class)
            ->resolve($item, 'US-LA', null, false);
    }

    public function test_manual_regular_item_allocates_with_zero_stock_without_extra_acknowledgement(): void
    {
        [$user, $order, $item, $product, $warehouse] = $this->ordinaryScenario();
        WarehouseInventory::query()->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)->update(['quantity_on_hand' => 0]);
        $allocation = app(FulfillmentAllocationSubmissionService::class)->submitManual($order, [
            ['order_item_id' => $item->id, 'source_code' => 'US-LA'],
        ], 'operator chose warehouse despite zero stock', $user, false);

        self::assertSame('submitted', $allocation->status);
        self::assertSame('US-LA', $order->fresh()->shipment?->warehouse?->code);
        self::assertSame('pending', $order->fresh()->shipment?->shipment_status);
        $audit = $allocation->plan_snapshot['stock_exception_acknowledgement'];
        self::assertSame($user->id, $audit['actor_id']);
        self::assertSame('insufficient', $audit['warnings'][0]['reason']);
        self::assertSame(0, WarehouseInventory::query()->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)->value('quantity_on_hand'));
    }

    public function test_manual_replacement_preserves_source_and_materializes_actual_product(): void
    {
        [$user, $order, $item, $product, $warehouse] = $this->ordinaryScenario();
        $target = $this->product('REPLACEMENT-01', 'Replacement product');
        $this->inventory($warehouse, $target, 7);
        config()->set('shipout.production_read_enabled', false);
        $allocation = app(FulfillmentAllocationSubmissionService::class)->submitManual($order, [
            ['order_item_id' => $item->id, 'source_code' => 'US-LA', 'replacement_product_id' => $target->id],
        ], null, $user);
        $actual = $item->fresh();
        self::assertSame($target->id, $actual->product_id);
        self::assertSame($target->sku, $actual->sku);
        self::assertSame($target->name, $actual->product_summary);
        self::assertSame($product->id, $actual->source_product_id);
        self::assertSame($item->sku, $actual->source_sku);
        self::assertSame($item->quantity, $actual->quantity);
        self::assertSame($item->line_revenue, $actual->line_revenue);
        self::assertSame($target->id, $allocation->manual_assignments[0]['replacement_product_id']);
        self::assertSame($target->id, $allocation->plan_snapshot['segments'][0]['items'][0]['resolved_product_id']);
        self::assertNull($allocation->plan_snapshot['stock_exception_acknowledgement']);
    }

    public function test_manual_replacement_rejects_inactive_product_without_writes(): void
    {
        [$user, $order, $item] = $this->ordinaryScenario();
        $target = $this->product('INACTIVE-01', 'Inactive product');
        $target->forceFill(['is_active' => false])->save();
        try {
            app(FulfillmentAllocationSubmissionService::class)->submitManual($order, [
                ['order_item_id' => $item->id, 'source_code' => 'US-LA', 'replacement_product_id' => $target->id],
            ], null, $user);
            self::fail('Inactive replacement was accepted');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->getStatusCode());
        }
        self::assertSame($item->product_id, $item->fresh()->product_id);
        self::assertNull($order->fresh()->fulfillmentAllocation);
    }

    public function test_manual_replacement_materializes_child_and_keeps_split_parent_source(): void
    {
        [$user, $order, $item, $product, $warehouse] = $this->ordinaryScenario();
        $target = $this->product('REPLACEMENT-SPLIT', 'Replacement split product');
        $this->inventory($warehouse, $target, 5);
        $otherWarehouse = $this->warehouse('US-NY');
        $other = $this->product('OTHER-SPLIT', 'Other original product');
        $this->inventory($otherWarehouse, $other, 4);
        $second = $order->items()->create([
            'product_id' => $other->id, 'sku' => $other->sku, 'product_summary' => $other->name,
            'quantity' => 1, 'unit_price' => '10.00', 'line_revenue' => '10.00',
        ]);
        config()->set('shipout.production_read_enabled', false);
        $allocation = app(FulfillmentAllocationSubmissionService::class)->submitManual($order, [
            ['order_item_id' => $item->id, 'source_code' => 'US-LA', 'replacement_product_id' => $target->id],
            ['order_item_id' => $second->id, 'source_code' => 'US-NY'],
        ], null, $user);
        self::assertSame($product->id, $item->fresh()->product_id);
        self::assertSame($product->sku, $item->fresh()->sku);
        $children = $order->fresh()->originalFulfillmentChildren()->with('items', 'shipment.warehouse')->get();
        self::assertCount(2, $children);
        $actual = $children->first(fn ($child) => $child->shipment->warehouse->code === 'US-LA')->items->sole();
        self::assertSame($target->id, $actual->product_id);
        self::assertSame($target->sku, $actual->sku);
        self::assertSame($product->id, $actual->source_product_id);
        self::assertSame($product->sku, $actual->source_sku);
        self::assertSame($item->line_revenue, $actual->line_revenue);
        self::assertSame('submitted', $allocation->status);
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
