<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ManualFulfillmentIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_logistics_shows_inventory_and_saves_manual_fulfillment(): void
    {
        [$user, $order, $product, $warehouse] = $this->scenario();
        WarehouseInventory::query()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            ['quantity_on_hand' => 12, 'quantity_reserved' => 3],
        );

        $this->actingAs($user)->get('/logistics/pending')->assertInertia(
            fn (Assert $page) => $page
                ->where('orders.data.0.id', $order->id)
                ->where('orders.data.0.items.0.availability', fn ($rows) => collect($rows)->contains(
                    fn ($row) => $row['warehouse_id'] === $warehouse->id && $row['quantity_available'] === 9
                ))
        );

        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/draft', [
            'warehouse_id' => $warehouse->id,
            'carrier' => 'UPS',
            'service' => 'Ground',
            'tracking_number' => 'DRAFT-TRACK',
        ])->assertRedirect();

        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'shipment_status' => 'pending',
            'warehouse_id' => $warehouse->id,
            'warehouse_selection_source' => 'manual',
            'carrier' => 'UPS',
            'service' => 'Ground',
            'carrier_selection_source' => 'manual',
            'tracking_number' => 'DRAFT-TRACK',
        ]);
        $this->assertDatabaseHas('audit_records', ['action' => 'shipment.fulfillment_draft_saved']);

        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/ship', [
            'warehouse_id' => $warehouse->id,
            'carrier' => 'UPS',
            'service' => 'Ground',
            'tracking_number' => 'SHIP-TRACK',
            'shipped_at' => '2026-08-27T05:00:00Z',
        ])->assertRedirect();

        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'shipment_status' => 'shipped',
            'warehouse_id' => $warehouse->id,
            'service' => 'Ground',
            'tracking_number' => 'SHIP-TRACK',
        ]);
        $this->assertDatabaseHas('manual_syncs', [
            'order_id' => $order->id,
            'website_sync_status' => 'pending',
        ]);
    }

    public function test_order_detail_exposes_inventory_and_selected_warehouse(): void
    {
        [$user, $order, $product, $warehouse] = $this->scenario();
        WarehouseInventory::query()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            ['quantity_on_hand' => 8, 'quantity_reserved' => 2],
        );
        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/draft', [
            'warehouse_id' => $warehouse->id,
        ])->assertRedirect();

        $this->actingAs($user)->get('/customers/orders/'.$order->id)->assertInertia(
            fn (Assert $page) => $page
                ->where('order.shipment.warehouse_id', $warehouse->id)
                ->where('order.shipment.warehouse_name', $warehouse->name)
                ->where("inventoryAvailability.{$product->id}", fn ($rows) => collect($rows)->contains(
                    fn ($row) => $row['warehouse_id'] === $warehouse->id && $row['quantity_available'] === 6
                ))
        );
    }

    private function scenario(): array
    {
        $user = $this->userWith(['orders.view', 'orders.create', 'logistics.view', 'logistics.operate']);
        $product = Product::firstOrCreate(
            ['sku' => 'MVP1-LINK-1'],
            ['name' => 'MVP1 Linked Product', 'product_type' => 'simple', 'woocommerce_product_id' => 991001],
        );
        $warehouse = Warehouse::query()->where('is_active', true)->orderBy('priority')->firstOrFail();

        $this->actingAs($user)->post('/customers/orders', [
            'external_order_number' => 'MVP1-'.uniqid(),
            'order_date' => '2026-08-27',
            'recipient_name' => 'Manual Fulfillment UAT',
            'country' => 'US',
            'items' => [[
                'product_id' => $product->id,
                'product_summary' => $product->name,
                'quantity' => 2,
                'unit_price' => '19.99',
            ]],
        ])->assertRedirect();

        return [$user, Order::query()->latest('id')->firstOrFail(), $product, $warehouse];
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'manual-fulfillment-'.uniqid(), 'label' => 'Manual Fulfillment']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
