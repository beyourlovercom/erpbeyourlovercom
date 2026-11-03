<?php

namespace Tests\Feature\Logistics;

use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\ShipmentAttempt;
use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReopenPreserveAllocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
    }

    public function test_shipped_derived_order_reopens_without_losing_warehouse_or_review(): void
    {
        [$manager, $child, $warehouse] = $this->scenario();

        $this->actingAs($manager)->post("/logistics/orders/{$child->id}/reopen", [
            'logistics_note' => 'Correct tracking',
        ])->assertRedirect();

        $this->assertDatabaseHas('shipments', [
            'order_id' => $child->id,
            'shipment_status' => 'pending',
            'warehouse_id' => $warehouse->id,
            'warehouse_selection_source' => 'manual',
            'carrier' => '4PX',
            'service' => 'Ground',
            'tracking_number' => null,
            'shipped_at' => null,
        ]);
        $this->assertDatabaseHas('shipment_attempts', [
            'order_id' => $child->id,
            'shipment_status' => 'shipped',
            'warehouse_id' => $warehouse->id,
            'tracking_number' => 'OLD-TRACK',
        ]);
        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $child->id,
            'status' => 'approved',
            'reviewed_warehouse_id' => $warehouse->id,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $child->id,
            'fulfillment_parent_id' => $child->fulfillment_parent_id,
            'order_status' => 'pending_shipment',
        ]);
        $this->actingAs($manager)->post("/logistics/orders/{$child->id}/reopen")->assertRedirect();
        $this->assertSame(1, ShipmentAttempt::query()->where('order_id', $child->id)->count());
    }

    public function test_authorized_manager_can_only_reopen_not_bypass_shipping(): void
    {
        [$manager, $child] = $this->scenario();
        $this->actingAs($manager)->post("/logistics/orders/{$child->id}/ship", [])->assertForbidden();
        $this->actingAs($manager)->post("/logistics/orders/{$child->id}/reopen")->assertRedirect();
    }

    public function test_readonly_user_cannot_reopen_shipped_child(): void
    {
        [, $child] = $this->scenario();
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'readonly-reopen-611', 'label' => 'Readonly']);
        $role->permissions()->firstOrCreate(['key' => 'orders.view'], ['label' => 'Order view']);
        $user->roles()->attach($role);
        $this->actingAs($user)->post("/logistics/orders/{$child->id}/reopen")->assertForbidden();
        $this->assertDatabaseHas('shipments', ['order_id' => $child->id, 'tracking_number' => 'OLD-TRACK']);
    }

    private function scenario(): array
    {
        $manager = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'super_admin', 'label' => 'Owner']);
        $manager->roles()->attach($role);

        $warehouse = Warehouse::query()->where('is_active', true)->orderBy('priority')->firstOrFail();
        $base = [
            'source_type' => 'manual_other',
            'order_date' => '2026-11-03',
            'currency' => 'USD',
            'order_status' => 'shipped',
            'owner_id' => $manager->id,
        ];
        $root = Order::query()->create([
            ...$base,
            'internal_order_number' => 'REOPEN-ROOT',
            'erp_order_id' => '007-1102-SAMPLE',
            'external_order_number' => 'REOPEN-ROOT',
            'order_kind' => 'customer',
        ]);
        $child = Order::query()->create([
            ...$base,
            'internal_order_number' => 'REOPEN-CHILD',
            'erp_order_id' => '007-1102-SAMPLEB',
            'external_order_number' => 'REOPEN-CHILD',
            'order_kind' => 'fulfillment_derived',
            'fulfillment_parent_id' => $root->id,
            'fulfillment_role' => 'original',
            'fulfillment_suffix' => 'B',
        ]);
        $child->shipment()->create([
            'shipment_status' => 'shipped',
            'warehouse_id' => $warehouse->id,
            'warehouse_selection_source' => 'manual',
            'carrier' => '4PX',
            'service' => 'Ground',
            'carrier_selection_source' => 'manual',
            'tracking_number' => 'OLD-TRACK',
            'shipped_at' => '2026-11-03 08:00:00',
        ]);
        OverseaWarehouseReview::query()->create([
            'order_id' => $child->id,
            'status' => 'approved',
            'submitted_warehouse_id' => $warehouse->id,
            'reviewed_warehouse_id' => $warehouse->id,
            'reviewed_by' => $manager->id,
            'reviewed_at' => now(),
        ]);
        return [$manager, $child, $warehouse];
    }
}
