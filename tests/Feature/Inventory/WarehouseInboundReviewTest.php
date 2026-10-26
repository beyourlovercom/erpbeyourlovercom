<?php

namespace Tests\Feature\Inventory;

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInboundBatch;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInboundItem;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventoryLot;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WarehouseInboundReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_review_matches_canonical_product_preserves_manifest_and_requires_separate_activation(): void
    {
        [$warehouse, $batch, $item] = $this->fixture();
        $product = Product::create(['sku' => 'PILLOW-GREEN', 'name' => 'Green Pillow', 'is_active' => true]);
        $operator = $this->userWith(['inventory.view', 'inventory.operate']);

        $this->actingAs($operator)->patch($this->url($warehouse, $batch, $item), $this->payload($item, $product))
            ->assertRedirect();

        $item->refresh();
        $this->assertSame($product->id, $item->product_id);
        $this->assertSame('PILLOW-GREEN', $item->canonical_sku);
        $this->assertSame('matched', $item->match_status);
        $this->assertNull($item->source_sku);
        $this->assertSame('Pillow mixed', $item->source_product_name);
        $this->assertSame('actual_manifest', $item->evidence_type);
        $this->assertSame(20, $item->quantity);
        $this->assertSame('received', $batch->fresh()->status);
        $this->assertDatabaseCount('warehouse_inventory', 0);
        $this->assertDatabaseCount('warehouse_inventory_lots', 0);

        $this->actingAs($operator)->patch("/inventory/{$warehouse->slug}/inbound/{$batch->id}/advance", ['status' => 'ready'])->assertRedirect();
        $this->assertSame('ready', $batch->fresh()->status);
        $this->assertSame(20, WarehouseInventory::where('warehouse_id', $warehouse->id)->firstOrFail()->quantity_on_hand);
        $this->assertSame(20, WarehouseInventoryLot::where('warehouse_id', $warehouse->id)->firstOrFail()->quantity);
        $this->actingAs($operator)->patch("/inventory/{$warehouse->slug}/inbound/{$batch->id}/advance", ['status' => 'ready'])->assertSessionHasErrors('status');
        $this->assertSame(20, WarehouseInventory::where('warehouse_id', $warehouse->id)->firstOrFail()->quantity_on_hand);
    }

    public function test_review_requires_permission_and_rejects_wrong_warehouse_or_batch(): void
    {
        [$warehouse, $batch, $item] = $this->fixture();
        $other = Warehouse::create(['slug' => 'another-wh', 'code' => 'ANOTHER-WH', 'name' => 'Another']);
        $product = Product::create(['sku' => 'REAL', 'name' => 'Real', 'is_active' => true]);
        $viewer = $this->userWith(['inventory.view']);
        $this->actingAs($viewer)->patch($this->url($warehouse, $batch, $item), $this->payload($item, $product))->assertForbidden();

        $operator = $this->userWith(['inventory.view', 'inventory.operate']);
        $this->actingAs($operator)->patch($this->url($other, $batch, $item), $this->payload($item, $product))->assertNotFound();
        $anotherBatch = WarehouseInboundBatch::create([
            'warehouse_id' => $warehouse->id, 'reference' => 'OTHER', 'transport_mode' => 'sea',
            'status' => 'received', 'quantity_total' => 20,
        ]);
        $this->actingAs($operator)->patch($this->url($warehouse, $anotherBatch, $item), $this->payload($item, $product))->assertNotFound();
        $this->assertNull($item->fresh()->product_id);
    }

    public function test_stale_row_inactive_product_and_already_matched_item_are_blocked(): void
    {
        [$warehouse, $batch, $item] = $this->fixture();
        $inactive = Product::create(['sku' => 'INACTIVE', 'name' => 'Inactive', 'is_active' => false]);
        $product = Product::create(['sku' => 'ACTIVE', 'name' => 'Active', 'is_active' => true]);
        $operator = $this->userWith(['inventory.view', 'inventory.operate']);

        $this->actingAs($operator)->patch($this->url($warehouse, $batch, $item), $this->payload($item, $inactive))
            ->assertSessionHasErrors('product_id');
        $stale = $this->payload($item, $product);
        sleep(1);
        $item->update(['note' => 'changed externally']);
        $this->actingAs($operator)->patch($this->url($warehouse, $batch, $item), $stale)
            ->assertSessionHasErrors('expected_updated_at');

        $this->actingAs($operator)->patch($this->url($warehouse, $batch, $item), $this->payload($item->fresh(), $product))
            ->assertRedirect();
        $this->actingAs($operator)->patch($this->url($warehouse, $batch, $item), $this->payload($item->fresh(), $product))
            ->assertSessionHasErrors('product_id');
        $this->assertSame(20, $item->fresh()->quantity);
    }

    public function test_review_rejected_before_received_and_after_ready(): void
    {
        [$warehouse, $batch, $item] = $this->fixture();
        $product = Product::create(['sku' => 'VALID', 'name' => 'Valid', 'is_active' => true]);
        $operator = $this->userWith(['inventory.view', 'inventory.operate']);

        $batch->update(['status' => 'in_transit']);
        $this->actingAs($operator)->patch($this->url($warehouse, $batch, $item), $this->payload($item, $product))
            ->assertSessionHasErrors('product_id');
        $batch->update(['status' => 'ready']);
        $this->actingAs($operator)->patch($this->url($warehouse, $batch, $item), $this->payload($item, $product))
            ->assertSessionHasErrors('product_id');
        $this->assertNull($item->fresh()->product_id);
        $this->assertDatabaseCount('warehouse_inventory', 0);
    }

    private function fixture(): array
    {
        $warehouse = Warehouse::create(['slug' => 'inbound-review', 'code' => 'INB-REVIEW', 'name' => 'Inbound Review']);
        $batch = WarehouseInboundBatch::create([
            'warehouse_id' => $warehouse->id, 'reference' => 'IB027260912RS-1',
            'transport_mode' => 'sea', 'status' => 'received', 'received_on' => '2026-10-26',
            'quantity_total' => 20, 'source_filename' => 'actual_manifest.xlsx',
        ]);
        $item = WarehouseInboundItem::create([
            'batch_id' => $batch->id, 'product_id' => null,
            'source_sku' => null, 'canonical_sku' => null, 'source_product_name' => 'Pillow mixed',
            'quantity' => 20, 'evidence_type' => 'actual_manifest', 'match_status' => 'review',
        ]);
        return [$warehouse, $batch, $item];
    }

    private function payload(WarehouseInboundItem $item, Product $product): array
    {
        return [
            'product_id' => $product->id,
            'expected_updated_at' => $item->updated_at->toIso8601String(),
            'reason' => 'Verified against actual carton label',
        ];
    }

    private function url(Warehouse $warehouse, WarehouseInboundBatch $batch, WarehouseInboundItem $item): string
    {
        return "/inventory/{$warehouse->slug}/inbound/{$batch->id}/items/{$item->id}/review";
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'review-test-'.uniqid(), 'label' => 'Inbound Review Test']);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $user->roles()->attach($role);
        return $user;
    }
}
