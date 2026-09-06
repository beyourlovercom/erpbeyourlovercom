<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\InventorySyncBatch;
use App\Modules\Inventory\Infrastructure\Models\PackagingMeasurementRecord;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Infrastructure\Models\ShipmentItemPackagingSnapshot;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductPackagingProfile;
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

    public function test_logistics_projects_safe_warehouse_recommendation_without_persisting_it(): void
    {
        [$user, $order, $product, $warehouse] = $this->scenario();
        $warehouse->serviceRegions()->create(['country_code' => 'US', 'priority' => 1]);
        InventorySyncBatch::query()->create([
            'warehouse_id' => $warehouse->id, 'source_type' => 'xlsx', 'sync_mode' => 'full',
            'status' => 'confirmed', 'confirmed_at' => now(),
        ]);
        WarehouseInventory::query()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            ['quantity_on_hand' => 5, 'quantity_reserved' => 1],
        );

        $this->actingAs($user)->get('/logistics/pending')->assertInertia(
            fn (Assert $page) => $page
                ->where('orders.data.0.warehouse_recommendation.status', 'recommended')
                ->where('orders.data.0.warehouse_recommendation.warehouse.id', $warehouse->id)
                ->where('orders.data.0.warehouse_recommendation.country_code', 'US')
        );

        $this->assertDatabaseMissing('shipments', ['order_id' => $order->id]);
    }


    public function test_order_uses_current_product_packaging_standard_and_fulfillment_freezes_snapshot(): void
    {
        [$user, $order, $product, $warehouse] = $this->scenario();
        $measurement = PackagingMeasurementRecord::query()->create([
            'product_id'=>$product->id,'warehouse_id'=>$warehouse->id,'status'=>'approved','packaging_method_key'=>'bubble_wrap_cut',
            'packaging_length_cm'=>'32.00','packaging_width_cm'=>'18.00','packaging_height_cm'=>'12.00','packaged_weight_kg'=>'0.850',
            'measured_at'=>'2026-09-04 10:00:00','created_by'=>$user->id,'reviewed_by'=>$user->id,'reviewed_at'=>now(),
        ]);
        $profile = ProductPackagingProfile::query()->create([
            'product_id'=>$product->id,'preset_key'=>'bubble_wrap_cut','current_measurement_record_id'=>$measurement->id,'is_active'=>true,
        ]);
        $item = $order->items()->firstOrFail();

        $this->actingAs($user)->get('/customers/orders/'.$order->id)->assertInertia(
            fn (Assert $page) => $page
                ->where("packagingReferences.{$item->id}.source", 'current_product_standard')
                ->where("packagingReferences.{$item->id}.packaged_weight_kg", '0.850')
                ->where("packagingReferences.{$item->id}.packaging_length_cm", '32.00')
        );

        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/draft', ['warehouse_id'=>$warehouse->id])->assertRedirect();
        $shipment = $order->fresh()->shipment()->firstOrFail();
        $snapshot = ShipmentItemPackagingSnapshot::query()->where('shipment_id',$shipment->id)->where('order_item_id',$item->id)->firstOrFail();
        $this->assertSame('0.850', $snapshot->packaged_weight_kg);
        $this->assertSame($measurement->id, $snapshot->packaging_measurement_record_id);

        $newMeasurement = PackagingMeasurementRecord::query()->create([
            'product_id'=>$product->id,'warehouse_id'=>$warehouse->id,'status'=>'approved','packaging_method_key'=>'carton',
            'packaging_length_cm'=>'30.00','packaging_width_cm'=>'17.00','packaging_height_cm'=>'11.00','packaged_weight_kg'=>'0.720',
            'measured_at'=>'2026-10-01 10:00:00','created_by'=>$user->id,'reviewed_by'=>$user->id,'reviewed_at'=>now(),
        ]);
        $profile->update(['current_measurement_record_id'=>$newMeasurement->id,'preset_key'=>'carton']);

        $this->actingAs($user)->get('/customers/orders/'.$order->id)->assertInertia(
            fn (Assert $page) => $page
                ->where("packagingReferences.{$item->id}.source", 'shipment_snapshot')
                ->where("packagingReferences.{$item->id}.packaged_weight_kg", '0.850')
                ->where("packagingReferences.{$item->id}.packaging_method_label", '气泡袋裁剪')
        );
        $this->actingAs($user)->get('/logistics/orders/'.$order->id.'/payload')
            ->assertJsonPath('items.0.packaging_reference.source', 'shipment_snapshot')
            ->assertJsonPath('items.0.packaging_reference.packaged_weight_kg', '0.850');
    }

    public function test_adopting_recommendation_creates_reservation_and_recommended_shipment_draft_idempotently(): void
    {
        [$user, $order, $product, $warehouse] = $this->scenario();
        $warehouse->serviceRegions()->create(['country_code' => 'US', 'priority' => 1]);
        InventorySyncBatch::query()->create([
            'warehouse_id' => $warehouse->id, 'source_type' => 'xlsx', 'sync_mode' => 'full',
            'status' => 'confirmed', 'confirmed_at' => now(),
        ]);
        WarehouseInventory::query()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            ['quantity_on_hand' => 5, 'quantity_reserved' => 0],
        );

        $url = '/logistics/orders/'.$order->id.'/adopt-recommendation';
        $this->actingAs($user)->post($url)->assertRedirect();
        $this->actingAs($user)->post($url)->assertRedirect();

        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'shipment_status' => 'pending',
            'warehouse_id' => $warehouse->id,
            'warehouse_selection_source' => 'recommended',
        ]);
        $this->assertDatabaseHas('inventory_reservations', [
            'order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'reserved',
        ]);
        $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertSame(2, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity_reserved'));
    }

    public function test_recommended_reservation_commits_when_order_is_marked_shipped(): void
    {
        [$user, $order, $product, $warehouse] = $this->scenario();
        $warehouse->serviceRegions()->create(['country_code' => 'US', 'priority' => 1]);
        InventorySyncBatch::query()->create([
            'warehouse_id' => $warehouse->id, 'source_type' => 'xlsx', 'sync_mode' => 'full',
            'status' => 'confirmed', 'confirmed_at' => now(),
        ]);
        WarehouseInventory::query()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            ['quantity_on_hand' => 5, 'quantity_reserved' => 0],
        );

        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/adopt-recommendation')->assertRedirect();
        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/ship', [
            'warehouse_id' => $warehouse->id,
            'carrier' => 'UPS',
            'service' => 'Ground',
            'tracking_number' => 'ASSIST-TRACK',
            'shipped_at' => '2026-09-05T12:00:00Z',
        ])->assertRedirect();

        $inventory = WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(3, $inventory->quantity_on_hand);
        $this->assertSame(0, $inventory->quantity_reserved);
        $this->assertDatabaseHas('inventory_reservations', ['order_id' => $order->id, 'status' => 'committed']);
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'warehouse_selection_source' => 'recommended', 'shipment_status' => 'shipped']);
    }

    public function test_shipping_cannot_bypass_an_active_reservation_with_another_warehouse(): void
    {
        [$user, $order, $product, $warehouse] = $this->scenario();
        $warehouse->serviceRegions()->create(['country_code' => 'US', 'priority' => 1]);
        InventorySyncBatch::query()->create([
            'warehouse_id' => $warehouse->id, 'source_type' => 'xlsx', 'sync_mode' => 'full',
            'status' => 'confirmed', 'confirmed_at' => now(),
        ]);
        WarehouseInventory::query()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            ['quantity_on_hand' => 5, 'quantity_reserved' => 0],
        );
        $other = Warehouse::query()->where('id', '!=', $warehouse->id)->where('is_active', true)->firstOrFail();

        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/adopt-recommendation')->assertRedirect();
        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/ship', [
            'warehouse_id' => $other->id,
            'carrier' => 'UPS',
            'service' => 'Ground',
            'shipped_at' => '2026-09-05T12:00:00Z',
        ])->assertStatus(409);

        $this->assertDatabaseHas('inventory_reservations', ['order_id' => $order->id, 'status' => 'reserved', 'warehouse_id' => $warehouse->id]);
        $this->assertSame(2, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity_reserved'));
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'shipment_status' => 'pending', 'warehouse_id' => $warehouse->id]);
    }

    public function test_manual_warehouse_override_releases_recommended_reservation(): void
    {
        [$user, $order, $product, $warehouse] = $this->scenario();
        $warehouse->serviceRegions()->create(['country_code' => 'US', 'priority' => 1]);
        InventorySyncBatch::query()->create([
            'warehouse_id' => $warehouse->id, 'source_type' => 'xlsx', 'sync_mode' => 'full',
            'status' => 'confirmed', 'confirmed_at' => now(),
        ]);
        WarehouseInventory::query()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            ['quantity_on_hand' => 5, 'quantity_reserved' => 0],
        );
        $other = Warehouse::query()->where('id', '!=', $warehouse->id)->where('is_active', true)->firstOrFail();

        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/adopt-recommendation')->assertRedirect();
        $this->actingAs($user)->post('/logistics/orders/'.$order->id.'/draft', [
            'warehouse_id' => $other->id,
            'carrier' => 'UPS',
            'service' => 'Ground',
        ])->assertRedirect();

        $this->assertDatabaseHas('inventory_reservations', ['order_id' => $order->id, 'status' => 'released', 'release_reason' => 'manual_warehouse_override']);
        $this->assertSame(0, WarehouseInventory::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity_reserved'));
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'warehouse_id' => $other->id, 'warehouse_selection_source' => 'manual']);
    }

    public function test_adopt_recommendation_returns_json_warehouse_when_accept_json(): void
    {
        [$user, $order, $product, $warehouse] = $this->scenario();
        $warehouse->serviceRegions()->create(['country_code' => 'US', 'priority' => 1]);
        InventorySyncBatch::query()->create([
            'warehouse_id' => $warehouse->id, 'source_type' => 'xlsx', 'sync_mode' => 'full',
            'status' => 'confirmed', 'confirmed_at' => now(),
        ]);
        WarehouseInventory::query()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            ['quantity_on_hand' => 5, 'quantity_reserved' => 0],
        );

        $response = $this->actingAs($user)
            ->postJson('/logistics/orders/'.$order->id.'/adopt-recommendation');

        $response->assertOk()
            ->assertJsonStructure(['warehouse_id', 'warehouse_name'])
            ->assertJsonPath('warehouse_id', $warehouse->id)
            ->assertJsonPath('warehouse_name', $warehouse->name);

        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'warehouse_id' => $warehouse->id,
            'warehouse_selection_source' => 'recommended',
        ]);
    }

    public function test_adopt_recommendation_json_non_redirect_does_not_navigate(): void
    {
        [$user, $order, $product, $warehouse] = $this->scenario();
        $warehouse->serviceRegions()->create(['country_code' => 'US', 'priority' => 1]);
        InventorySyncBatch::query()->create([
            'warehouse_id' => $warehouse->id, 'source_type' => 'xlsx', 'sync_mode' => 'full',
            'status' => 'confirmed', 'confirmed_at' => now(),
        ]);
        WarehouseInventory::query()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id],
            ['quantity_on_hand' => 5, 'quantity_reserved' => 0],
        );

        $response = $this->actingAs($user)
            ->postJson('/logistics/orders/'.$order->id.'/adopt-recommendation');

        $response->assertOk()->assertHeader('content-type', 'application/json');
        $this->assertFalse($response->isRedirect(), 'JSON path must not redirect (no Inertia navigation)');
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
