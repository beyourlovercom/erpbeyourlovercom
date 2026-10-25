<?php

namespace Tests\Feature\Inventory;

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Infrastructure\Models\InventoryReservation;
use App\Modules\Inventory\Infrastructure\Models\InventoryReservationItem;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class WarehouseInlineEditV1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_inventory_cell_update_requires_inventory_permission_and_does_not_leak_warehouse_scope(): void
    {
        $viewer = $this->userWith(['inventory.view']);
        [$warehouse, $inventory] = $this->stock('inline-permission', 'INLINE-PERM');

        $this->actingAs($viewer)->patch("/inventory/{$warehouse->slug}/stock/{$inventory->id}/cell", $this->payload($inventory, 'location', 'A-2'))
            ->assertForbidden();

        [$otherWarehouse, $otherInventory] = $this->stock('inline-other', 'INLINE-OTHER');
        $operator = $this->userWith(['inventory.view', 'inventory.operate']);
        $this->actingAs($operator)->patch("/inventory/{$warehouse->slug}/stock/{$otherInventory->id}/cell", $this->payload($otherInventory, 'location', 'LEAK'))
            ->assertNotFound();
        $this->assertSame('inline-other', $otherWarehouse->slug);
    }

    public function test_single_cell_patch_preserves_other_fields_and_recomputes_available_projection(): void
    {
        $operator = $this->userWith(['inventory.view', 'inventory.operate']);
        [$warehouse, $inventory] = $this->stock('inline-crud', 'INLINE-CRUD', 10, 3);

        $this->actingAs($operator)->patch("/inventory/{$warehouse->slug}/stock/{$inventory->id}/cell", $this->payload($inventory, 'quantity_on_hand', 12))
            ->assertRedirect();

        $inventory->refresh();
        $this->assertSame(12, $inventory->quantity_on_hand);
        $this->assertSame(3, $inventory->quantity_reserved);
        $this->assertSame('A-1', $inventory->location);
        $this->assertDatabaseHas('inventory_adjustments', [
            'warehouse_inventory_id' => $inventory->id,
            'quantity_before' => 10,
            'quantity_after' => 12,
        ]);

        $this->actingAs($operator)->get("/inventory/{$warehouse->slug}")->assertInertia(fn (Assert $page) => $page
            ->where('warehouse.inventories.0.quantity_available', 9)
        );
    }

    public function test_stale_and_unsafe_quantity_updates_fail_closed(): void
    {
        $operator = $this->userWith(['inventory.view', 'inventory.operate']);
        [$warehouse, $inventory] = $this->stock('inline-stale', 'INLINE-STALE', 10, 3);
        $stale = $this->payload($inventory, 'quantity_on_hand', 20);
        sleep(1);
        $inventory->update(['location' => 'CONCURRENT']);

        $this->actingAs($operator)->patch("/inventory/{$warehouse->slug}/stock/{$inventory->id}/cell", $stale)
            ->assertSessionHasErrors('value');
        $this->assertSame(10, $inventory->fresh()->quantity_on_hand);

        $fresh = $inventory->fresh();
        $this->actingAs($operator)->patch("/inventory/{$warehouse->slug}/stock/{$inventory->id}/cell", $this->payload($fresh, 'quantity_on_hand', 2))
            ->assertSessionHasErrors('value');
        $this->assertSame(10, $inventory->fresh()->quantity_on_hand);

        $order = Order::create([
            'internal_order_number' => 'INLINE-ORDER-1', 'source_type' => 'manual', 'order_date' => '2026-10-01',
            'order_status' => 'pending_shipment',
        ]);
        $reservation = InventoryReservation::create([
            'reservation_key' => 'inline-reservation', 'payload_hash' => hash('sha256', 'inline'),
            'order_id' => $order->id, 'warehouse_id' => $warehouse->id, 'status' => 'reserved',
        ]);
        InventoryReservationItem::create([
            'inventory_reservation_id' => $reservation->id, 'warehouse_inventory_id' => $inventory->id,
            'product_id' => $inventory->product_id, 'quantity' => 4,
        ]);
        $fresh = $inventory->fresh();
        $this->actingAs($operator)->patch("/inventory/{$warehouse->slug}/stock/{$inventory->id}/cell", $this->payload($fresh, 'quantity_reserved', 2))
            ->assertSessionHasErrors('value');
        $this->assertSame(3, $inventory->fresh()->quantity_reserved);
    }

    public function test_product_cells_require_products_permission_and_use_global_product_update(): void
    {
        $inventoryOperator = $this->userWith(['inventory.view', 'inventory.operate']);
        [$warehouse, $inventory] = $this->stock('inline-product', 'INLINE-PRODUCT');
        $payload = $this->payload($inventory, 'sku', 'INLINE-NEW');
        $payload['expected_product_updated_at'] = $inventory->product->updated_at->toIso8601String();

        $this->actingAs($inventoryOperator)->patch("/inventory/{$warehouse->slug}/stock/{$inventory->id}/cell", $payload)->assertForbidden();

        $operator = $this->userWith(['inventory.view', 'inventory.operate', 'products.operate']);
        $this->actingAs($operator)->patch("/inventory/{$warehouse->slug}/stock/{$inventory->id}/cell", $payload)->assertRedirect();
        $this->assertSame('INLINE-NEW', $inventory->product->fresh()->sku);

        $collision = Product::create(['sku' => 'INLINE-COLLISION', 'name' => 'Collision', 'is_active' => true]);
        $fresh = $inventory->fresh(['product']);
        $payload = $this->payload($fresh, 'sku', $collision->sku);
        $payload['expected_product_updated_at'] = $fresh->product->updated_at->toIso8601String();
        $this->actingAs($operator)->patch("/inventory/{$warehouse->slug}/stock/{$inventory->id}/cell", $payload)->assertSessionHasErrors('value');
        $this->assertSame('INLINE-NEW', $inventory->product->fresh()->sku);
    }

    /** @return array{0:Warehouse,1:WarehouseInventory} */
    private function stock(string $slug, string $sku, int $onHand = 10, int $reserved = 3): array
    {
        $warehouse = Warehouse::create(['slug' => $slug, 'code' => strtoupper($slug), 'name' => $slug]);
        $product = Product::create(['sku' => $sku, 'name' => "Product {$sku}", 'is_active' => true]);
        $inventory = WarehouseInventory::create([
            'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
            'quantity_on_hand' => $onHand, 'quantity_reserved' => $reserved,
            'location' => 'A-1', 'expected_restock_qty' => 2,
        ]);
        return [$warehouse, $inventory->fresh(['product'])];
    }

    /** @return array<string,mixed> */
    private function payload(WarehouseInventory $inventory, string $field, mixed $value): array
    {
        return [
            'field' => $field, 'value' => $value,
            'expected_updated_at' => $inventory->updated_at->toIso8601String(),
        ];
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'inline-test-'.uniqid(), 'label' => 'Inline Test']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->attach($role);
        return $user;
    }
}
