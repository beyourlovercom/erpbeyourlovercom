<?php

namespace Tests\Feature\Inventory;

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Application\GiftSkuResolutionService;
use App\Modules\Inventory\Application\WarehouseClearanceService;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsInventorySnapshot;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class WarehouseClearanceGiftPoolV1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_clearance_mutation_reuses_inventory_operate_permission(): void
    {
        $view = $this->userWith(['inventory.view']);
        $product = $this->stockedProduct('us-la', 'CLR-PERM', 5, 0);

        $this->actingAs($view)->post('/inventory/us-la/clearance', [
            'product_ids' => [$product->id],
        ])->assertForbidden();
    }

    public function test_operator_can_add_multiple_only_from_selected_warehouse_inventory(): void
    {
        $user = $this->userWith(['inventory.view', 'inventory.operate']);
        $first = $this->stockedProduct('us-la', 'CLR-A', 12, 2);
        $second = $this->stockedProduct('us-la', 'CLR-B', 4, 0);
        $otherWarehouseProduct = $this->stockedProduct('us-ny', 'CLR-NY', 9, 0);

        $this->actingAs($user)->post('/inventory/us-la/clearance', [
            'product_ids' => [$first->id, $otherWarehouseProduct->id],
        ])->assertSessionHasErrors('product_ids');
        $this->assertDatabaseMissing('warehouse_gift_sku_mappings', [
            'warehouse_id' => Warehouse::where('slug', 'us-la')->value('id'),
            'fulfillment_product_id' => $first->id,
        ]);

        $this->actingAs($user)->post('/inventory/us-la/clearance', [
            'product_ids' => [$first->id, $second->id],
        ])->assertRedirect();

        $warehouseId = (int) Warehouse::where('slug', 'us-la')->value('id');
        $this->assertDatabaseHas('warehouse_gift_sku_mappings', [
            'warehouse_id' => $warehouseId, 'source_sku' => 'gift',
            'fulfillment_product_id' => $first->id, 'is_active' => 1,
        ]);
        $this->assertDatabaseHas('warehouse_gift_sku_mappings', [
            'warehouse_id' => $warehouseId, 'source_sku' => 'gift',
            'fulfillment_product_id' => $second->id, 'is_active' => 1,
        ]);
        $this->assertDatabaseHas('audit_records', ['action' => 'warehouse.clearance_added']);
    }

    public function test_warehouse_detail_exposes_live_clearance_stock_and_candidates(): void
    {
        $user = $this->userWith(['inventory.view', 'inventory.operate']);
        $product = $this->stockedProduct('us-la', 'CLR-LIVE', 10, 3);
        $this->actingAs($user)->post('/inventory/us-la/clearance', [
            'product_ids' => [$product->id],
        ])->assertRedirect();

        $this->actingAs($user)->get('/inventory/us-la')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/WarehouseShow')
            ->where('clearanceProducts.0.product.sku', 'CLR-LIVE')
            ->where('clearanceProducts.0.quantity_on_hand', 10)
            ->where('clearanceProducts.0.quantity_reserved', 3)
            ->where('clearanceProducts.0.quantity_available', 7)
            ->where('clearanceProducts.0.status', 'available')
            ->where('clearanceCandidates.0.already_clearance', true)
        );
    }

    public function test_gift_pool_availability_is_live_and_not_copied(): void
    {
        $user = $this->userWith(['inventory.view', 'inventory.operate']);
        $product = $this->stockedProduct('us-la', 'CLR-STOCK', 2, 2);
        $warehouse = Warehouse::where('slug', 'us-la')->firstOrFail();
        $this->actingAs($user)->post('/inventory/us-la/clearance', [
            'product_ids' => [$product->id],
        ])->assertRedirect();

        $resolver = app(GiftSkuResolutionService::class);
        $key = strtoupper((string) $warehouse->code);
        $options = $resolver->options('gift', 1);
        $this->assertFalse($options[$key][0]['can_fulfill']);
        $this->assertSame(0, $options[$key][0]['quantity_available']);

        WarehouseInventory::where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->update(['quantity_on_hand' => 10, 'quantity_reserved' => 2]);

        $options = $resolver->options('gift', 1);
        $this->assertTrue($options[$key][0]['can_fulfill']);
        $this->assertSame(8, $options[$key][0]['quantity_available']);
    }

    public function test_gift_pool_never_leaks_across_warehouses_and_respects_priority(): void
    {
        $user = $this->userWith(['inventory.view', 'inventory.operate']);
        $laA = $this->stockedProduct('us-la', 'CLR-LA-A', 8, 0);
        $laB = $this->stockedProduct('us-la', 'CLR-LA-B', 8, 0);
        $ny = $this->stockedProduct('us-ny', 'CLR-NY-A', 8, 0);

        $this->actingAs($user)->post('/inventory/us-la/clearance', [
            'product_ids' => [$laA->id, $laB->id],
        ])->assertRedirect();
        $this->actingAs($user)->post('/inventory/us-ny/clearance', [
            'product_ids' => [$ny->id],
        ])->assertRedirect();

        $la = Warehouse::where('slug', 'us-la')->firstOrFail();
        $nyWarehouse = Warehouse::where('slug', 'us-ny')->firstOrFail();
        $laMapping = (int) DB::table('warehouse_gift_sku_mappings')
            ->where('warehouse_id', $la->id)->where('fulfillment_product_id', $laB->id)->value('id');
        $this->actingAs($user)->patch("/inventory/us-la/clearance/{$laMapping}", ['priority' => 1])->assertRedirect();

        $options = app(GiftSkuResolutionService::class)->options('gift', 1);
        $laSkus = collect($options[strtoupper((string) $la->code)])->pluck('fulfillment_sku')->all();
        $nySkus = collect($options[strtoupper((string) $nyWarehouse->code)])->pluck('fulfillment_sku')->all();
        $this->assertSame('CLR-LA-B', $laSkus[0]);
        $this->assertEqualsCanonicalizing(['CLR-LA-A', 'CLR-LA-B'], $laSkus);
        $this->assertSame(['CLR-NY-A'], $nySkus);
    }

    public function test_removing_clearance_immediately_removes_it_from_gift_pool(): void
    {
        $user = $this->userWith(['inventory.view', 'inventory.operate']);
        $product = $this->stockedProduct('us-la', 'CLR-REMOVE', 8, 0);
        $warehouse = Warehouse::where('slug', 'us-la')->firstOrFail();
        $this->actingAs($user)->post('/inventory/us-la/clearance', [
            'product_ids' => [$product->id],
        ])->assertRedirect();

        $mappingId = (int) DB::table('warehouse_gift_sku_mappings')
            ->where('warehouse_id', $warehouse->id)
            ->where('fulfillment_product_id', $product->id)
            ->value('id');
        $this->assertArrayHasKey(strtoupper((string) $warehouse->code), app(GiftSkuResolutionService::class)->options('gift', 1));

        $this->actingAs($user)->delete("/inventory/us-la/clearance/{$mappingId}")->assertRedirect();
        $this->assertDatabaseHas('warehouse_gift_sku_mappings', ['id' => $mappingId, 'is_active' => 0]);
        $this->assertDatabaseHas('audit_records', ['action' => 'warehouse.clearance_removed']);
        $this->assertArrayNotHasKey(strtoupper((string) $warehouse->code), app(GiftSkuResolutionService::class)->options('gift', 1));
    }

    public function test_us_sm_gift_oms_snapshot_blocked_is_diagnosed_and_recovers_only_after_applied_snapshot(): void
    {
        $user = $this->userWith(['inventory.view', 'inventory.operate']);
        $warehouse = Warehouse::where('code', 'US-SM')->firstOrFail();
        $product = $this->stockedProduct($warehouse->slug, 'CLR-OMS-BLOCKED', 10, 0);
        $this->actingAs($user)->post("/inventory/{$warehouse->slug}/clearance", [
            'product_ids' => [$product->id],
        ])->assertRedirect();

        $blocked = XlwmsInventorySnapshot::query()->create([
            'warehouse_id' => $warehouse->id, 'wh_code' => 'OMS-US-SM',
            'checksum' => hash('sha256', 'blocked-'.uniqid()), 'status' => 'blocked',
            'fetched_at' => now(), 'summary' => ['v2' => true],
        ]);
        $resolver = app(GiftSkuResolutionService::class);
        $blockedOption = $resolver->options('gift', 1)['US-SM'][0];
        self::assertFalse($blockedOption['can_fulfill']);
        self::assertSame(0, $blockedOption['quantity_available']);
        self::assertSame(10, $blockedOption['erp_quantity_available']);
        self::assertSame('oms_snapshot_blocked', $blockedOption['stock_gate_reason']);

        $clearance = app(WarehouseClearanceService::class)->listForWarehouse($warehouse);
        self::assertSame(10, $clearance[0]['quantity_available']);
        self::assertSame(0, $clearance[0]['gift_quantity_available']);
        self::assertTrue($clearance[0]['gift_stock_mismatch']);
        self::assertSame('oms_snapshot_blocked', $clearance[0]['gift_stock_gate_reason']);

        $this->actingAs($user)->get("/inventory/{$warehouse->slug}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inventory/WarehouseShow')
                ->where('clearanceProducts.0.gift_stock_gate_reason', 'oms_snapshot_blocked')
                ->where('clearanceProducts.0.gift_stock_mismatch', true));

        $applied = XlwmsInventorySnapshot::query()->create([
            'warehouse_id' => $warehouse->id, 'wh_code' => 'OMS-US-SM',
            'checksum' => hash('sha256', 'applied-'.uniqid()), 'status' => 'applied',
            'fetched_at' => now(), 'applied_at' => now(), 'summary' => ['v2' => true],
        ]);
        $applied->rows()->create([
            'provider_sku' => 'CLR-OMS-BLOCKED', 'product_id' => $product->id,
            'resolution_status' => 'matched', 'oms_total' => 10,
            'oms_available' => 7, 'oms_locked' => 3, 'oms_in_transit' => 0,
        ]);
        $ready = $resolver->options('gift', 1)['US-SM'][0];
        self::assertTrue($ready['can_fulfill']);
        self::assertSame(7, $ready['quantity_available']);
        self::assertNull($ready['stock_gate_reason']);
        self::assertSame(10, $ready['erp_quantity_available']);
        self::assertSame($blocked->id + 1, $applied->id);
    }

    private function stockedProduct(string $warehouseSlug, string $sku, int $onHand, int $reserved): Product
    {
        $product = Product::create(['sku' => $sku, 'name' => $sku, 'source_type' => 'manual', 'product_type' => 'simple', 'is_active' => true]);
        $warehouse = Warehouse::where('slug', $warehouseSlug)->firstOrFail();
        WarehouseInventory::create(['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity_on_hand' => $onHand, 'quantity_reserved' => $reserved]);
        return $product;
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'clearance-test-'.uniqid(), 'label' => 'Clearance Test']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->attach($role);
        return $user;
    }
}
