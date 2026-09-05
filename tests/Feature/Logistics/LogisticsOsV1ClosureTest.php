<?php

namespace Tests\Feature\Logistics;

use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\Carrier;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class LogisticsOsV1ClosureTest extends TestCase
{
    use RefreshDatabase;

    // ── Acceptance criteria 2 & 3: dispatch without tracking ──────────────────

    public function test_dispatch_without_tracking_is_accepted(): void
    {
        [$user, $order, $carrier] = $this->scenario();
        $warehouse = Warehouse::query()->where('is_active', true)->orderBy('priority')->firstOrFail();

        $this->actingAs($user)->post("/logistics/orders/{$order->id}/ship", [
            'warehouse_id' => $warehouse->id,
            'carrier' => $carrier->name,
            'service' => 'Ground',
            'shipped_at' => '2026-09-01T08:00:00Z',
        ])->assertRedirect();

        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'shipment_status' => 'shipped',
            'tracking_number' => null,
        ]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'order_status' => 'shipped']);
        $this->assertDatabaseMissing('manual_syncs', ['order_id' => $order->id]);
        $this->assertDatabaseHas('audit_records', ['action' => 'shipment.marked_shipped']);
    }

    public function test_dispatch_requires_warehouse_id(): void
    {
        [$user, $order, $carrier] = $this->scenario();

        $this->actingAs($user)->post("/logistics/orders/{$order->id}/ship", [
            'carrier' => $carrier->name,
            'service' => 'Ground',
            'shipped_at' => '2026-09-01T08:00:00Z',
        ])->assertSessionHasErrors('warehouse_id');
    }

    public function test_dispatch_requires_service(): void
    {
        [$user, $order, $carrier] = $this->scenario();
        $warehouse = Warehouse::query()->where('is_active', true)->orderBy('priority')->firstOrFail();

        $this->actingAs($user)->post("/logistics/orders/{$order->id}/ship", [
            'warehouse_id' => $warehouse->id,
            'carrier' => $carrier->name,
            'shipped_at' => '2026-09-01T08:00:00Z',
        ])->assertSessionHasErrors('service');
    }

    public function test_dispatch_without_tracking_appears_in_awaiting_tracking_queue(): void
    {
        [$user, $order, $carrier] = $this->scenario();
        $this->dispatch($user, $order, $carrier, null);

        $this->actingAs($user)->get('/logistics/awaiting-tracking')->assertInertia(
            fn (Assert $page) => $page
                ->component('Logistics/AwaitingTracking')
                ->where('shipments.data.0.order_id', $order->id)
                ->where('shipments.data.0.tracking_number', null)
        );
    }

    public function test_dispatched_without_tracking_does_not_appear_in_in_transit(): void
    {
        [$user, $order, $carrier] = $this->scenario();
        $this->dispatch($user, $order, $carrier, null);

        $this->actingAs($user)->get('/logistics/in-transit')->assertInertia(
            fn (Assert $page) => $page
                ->component('Logistics/InTransit')
                ->where('shipments.total', 0)
        );
    }

    // ── Acceptance criteria 4 & 5: tracking update ────────────────────────────

    public function test_update_tracking_moves_shipment_to_in_transit_and_arms_sync(): void
    {
        [$user, $order, $carrier] = $this->scenario();
        $this->dispatch($user, $order, $carrier, null);

        $this->actingAs($user)->post("/logistics/orders/{$order->id}/update-tracking", [
            'tracking_number' => 'TRACK-001',
        ])->assertRedirect();

        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'shipment_status' => 'shipped',
            'tracking_number' => 'TRACK-001',
        ]);
        $this->assertDatabaseHas('manual_syncs', ['order_id' => $order->id, 'website_sync_status' => 'pending']);
        $this->assertDatabaseHas('audit_records', ['action' => 'shipment.tracking_updated']);
    }

    public function test_after_tracking_update_shipment_appears_only_in_in_transit(): void
    {
        [$user, $order, $carrier] = $this->scenario();
        $this->dispatch($user, $order, $carrier, null);
        $this->actingAs($user)->post("/logistics/orders/{$order->id}/update-tracking", ['tracking_number' => 'TN-999'])->assertRedirect();

        $this->actingAs($user)->get('/logistics/awaiting-tracking')->assertInertia(
            fn (Assert $page) => $page->where('shipments.total', 0)
        );
        $this->actingAs($user)->get('/logistics/in-transit')->assertInertia(
            fn (Assert $page) => $page->where('shipments.data.0.order_id', $order->id)
        );
    }

    public function test_no_duplicate_shipment_after_tracking_update(): void
    {
        [$user, $order, $carrier] = $this->scenario();
        $this->dispatch($user, $order, $carrier, null);
        $this->actingAs($user)->post("/logistics/orders/{$order->id}/update-tracking", ['tracking_number' => 'TN-DUP'])->assertRedirect();

        $this->assertEquals(1, Shipment::query()->where('order_id', $order->id)->count());
    }

    // ── Acceptance criteria 6 & 7: exception lifecycle ────────────────────────

    public function test_mark_exception_moves_to_exceptions_queue(): void
    {
        [$user, $order, $carrier] = $this->scenario();
        $this->dispatch($user, $order, $carrier, 'TN-EX');

        $this->actingAs($user)->post("/logistics/orders/{$order->id}/mark-exception", [
            'logistics_note' => 'Package lost',
        ])->assertRedirect();

        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'shipment_status' => 'exception',
            'tracking_number' => 'TN-EX',
        ]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'order_status' => 'shipped']);
        $this->assertDatabaseHas('audit_records', ['action' => 'shipment.exception_marked']);
    }

    public function test_exception_appears_in_exceptions_queue(): void
    {
        [$user, $order, $carrier] = $this->scenario();
        $this->dispatch($user, $order, $carrier, 'TN-EX2');
        $this->actingAs($user)->post("/logistics/orders/{$order->id}/mark-exception", ['logistics_note' => 'Returned'])->assertRedirect();

        $this->actingAs($user)->get('/logistics/exceptions')->assertInertia(
            fn (Assert $page) => $page
                ->component('Logistics/Exceptions')
                ->where('shipments.data.0.order_id', $order->id)
                ->where('shipments.data.0.shipment_status', 'exception')
        );
    }

    public function test_resolve_exception_returns_to_in_transit(): void
    {
        [$user, $order, $carrier] = $this->scenario();
        $this->dispatch($user, $order, $carrier, 'TN-RES');
        $this->actingAs($user)->post("/logistics/orders/{$order->id}/mark-exception", ['logistics_note' => 'Delayed'])->assertRedirect();

        $this->actingAs($user)->post("/logistics/orders/{$order->id}/resolve-exception")->assertRedirect();

        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'shipment_status' => 'shipped',
            'tracking_number' => 'TN-RES',
        ]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'order_status' => 'shipped']);
        $this->assertDatabaseHas('audit_records', ['action' => 'shipment.exception_resolved']);
    }

    public function test_resolve_exception_without_tracking_returns_to_awaiting_tracking(): void
    {
        [$user, $order, $carrier] = $this->scenario();
        $this->dispatch($user, $order, $carrier, null);
        $shipment = Shipment::query()->where('order_id', $order->id)->firstOrFail();
        $shipment->forceFill(['shipment_status' => 'exception', 'logistics_note' => 'Test'])->save();

        $this->actingAs($user)->post("/logistics/orders/{$order->id}/resolve-exception")->assertRedirect();

        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'shipment_status' => 'shipped',
            'tracking_number' => null,
        ]);
    }

    // ── Acceptance criteria 8: reopen still works ──────────────────────────────

    public function test_reopen_returns_to_pending_shipment(): void
    {
        [$user, $order, $carrier] = $this->scenario();
        $this->dispatch($user, $order, $carrier, 'TN-REOPEN');

        $this->actingAs($user)->post("/logistics/orders/{$order->id}/reopen", ['logistics_note' => 'Wrong address'])->assertRedirect();

        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'shipment_status' => 'pending', 'tracking_number' => null]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'order_status' => 'pending_shipment']);
    }

    // ── Acceptance criteria 10: authorization ─────────────────────────────────

    public function test_unauthenticated_cannot_access_queues(): void
    {
        foreach (['/logistics/awaiting-tracking', '/logistics/in-transit', '/logistics/exceptions'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
    }

    public function test_user_without_logistics_view_cannot_access_queues(): void
    {
        $user = $this->userWith([]);
        foreach (['/logistics/awaiting-tracking', '/logistics/in-transit', '/logistics/exceptions'] as $path) {
            $this->actingAs($user)->get($path)->assertForbidden();
        }
    }

    public function test_user_without_logistics_operate_cannot_update_tracking(): void
    {
        [$user, $order, $carrier] = $this->scenario();
        $this->dispatch($user, $order, $carrier, null);
        $viewOnly = $this->userWith(['logistics.view']);
        $this->actingAs($viewOnly)->post("/logistics/orders/{$order->id}/update-tracking", ['tracking_number' => 'X'])->assertForbidden();
    }

    // ── Idempotency ────────────────────────────────────────────────────────────

    public function test_mark_exception_is_idempotent(): void
    {
        [$user, $order, $carrier] = $this->scenario();
        $this->dispatch($user, $order, $carrier, 'TN-IDEM');
        $this->actingAs($user)->post("/logistics/orders/{$order->id}/mark-exception", ['logistics_note' => 'Delay'])->assertRedirect();
        $this->actingAs($user)->post("/logistics/orders/{$order->id}/mark-exception", ['logistics_note' => 'Delay'])->assertRedirect();

        $this->assertEquals(1, Shipment::query()->where('order_id', $order->id)->count());
    }

    public function test_resolve_exception_is_idempotent(): void
    {
        [$user, $order, $carrier] = $this->scenario();
        $this->dispatch($user, $order, $carrier, 'TN-IDEM2');
        $this->actingAs($user)->post("/logistics/orders/{$order->id}/mark-exception", ['logistics_note' => 'Y'])->assertRedirect();
        $this->actingAs($user)->post("/logistics/orders/{$order->id}/resolve-exception")->assertRedirect();
        $this->actingAs($user)->post("/logistics/orders/{$order->id}/resolve-exception")->assertRedirect();

        $this->assertEquals(1, Shipment::query()->where('order_id', $order->id)->count());
    }

    public function test_review_blocks_fulfillment_and_default_approval_returns_to_pending(): void
    {
        [$user, $order, $carrier] = $this->scenario();
        $this->dispatch($user, $order, $carrier, 'TN-REVIEW');
        $this->actingAs($user)->post("/logistics/orders/{$order->id}/mark-exception", ['logistics_note' => 'Need review'])->assertRedirect();
        $this->actingAs($user)->post("/logistics/orders/{$order->id}/redispatch", ['logistics_note' => 'Redispatch review'])->assertRedirect();
        $this->actingAs($user)->post("/logistics/orders/{$order->id}/resolve-exception")->assertStatus(409);
        $this->assertDatabaseHas('logistics_reviews', ['order_id' => $order->id, 'status' => 'pending', 'trigger' => 'redispatch']);
        $this->actingAs($user)->post("/logistics/orders/{$order->id}/review", ['destination' => 'pending', 'decision_note' => 'Approved for pending'])->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'order_status' => 'pending_shipment']);
        $this->assertDatabaseHas('logistics_review_histories', ['order_id' => $order->id, 'event' => 'decided']);
    }

    public function test_reship_snapshots_immutable_attempt_and_resets_active_one_to_one_shipment(): void
    {
        [$user, $order, $carrier] = $this->scenario();
        $this->dispatch($user, $order, $carrier, 'TN-OLD');
        $this->actingAs($user)->post("/logistics/orders/{$order->id}/mark-exception", ['logistics_note' => 'Reship'])->assertRedirect();
        $this->actingAs($user)->post("/logistics/orders/{$order->id}/reship", ['logistics_note' => 'New attempt'])->assertRedirect();
        $this->assertDatabaseHas('shipment_attempts', ['order_id' => $order->id, 'tracking_number' => 'TN-OLD', 'shipment_status' => 'exception']);
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'tracking_number' => null, 'shipment_status' => 'pending']);
        $this->assertEquals(1, Shipment::query()->where('order_id', $order->id)->count());
    }

    public function test_cancelled_projection_includes_cancelled_order_without_shipment(): void
    {
        [$user, $order] = $this->scenario();
        $order->forceFill(['order_status' => 'cancelled'])->save();
        $this->actingAs($user)->get('/logistics/cancelled')->assertInertia(fn (Assert $page) => $page
            ->where('orders.data.0.order_id', $order->id)
            ->where('orders.data.0.order_url', "/customers/orders/{$order->id}"));
    }

    public function test_entity_links_and_status_bar_are_server_provided(): void
    {
        [$user, $order, $carrier] = $this->scenario();
        $this->dispatch($user, $order, $carrier, 'TN-LINK');
        $this->actingAs($user)->get('/logistics/in-transit')->assertInertia(fn (Assert $page) => $page
            ->where('shipments.data.0.order_url', "/customers/orders/{$order->id}")
            ->where('shipments.data.0.items.0.product_url', '/products/'.$order->items()->firstOrFail()->product_id)
            ->where('statusBar.0.label', '待发货')
            ->where('statusBar.7.label', '亏本单'));
    }

    public function test_mark_completed_moves_shipment_to_completed_projection(): void
    {
        [$user, $order, $carrier] = $this->scenario();
        $this->dispatch($user, $order, $carrier, 'TN-COMPLETE');
        $this->actingAs($user)->post("/logistics/orders/{$order->id}/mark-completed")->assertRedirect();
        $this->assertDatabaseHas('shipments', ['order_id' => $order->id, 'shipment_status' => 'completed']);
        $this->actingAs($user)->get('/logistics/completed')->assertInertia(fn (Assert $page) => $page->where('shipments.data.0.order_id', $order->id));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function scenario(): array
    {
        $user = $this->userWith(['orders.view', 'orders.create', 'logistics.view', 'logistics.operate']);
        $carrier = Carrier::query()->firstOrCreate(['name' => 'UPS'], ['is_active' => true]);
        $product = Product::firstOrCreate(
            ['sku' => 'LOG-OS-V1-1'],
            ['name' => 'Logistics OS V1 Test Product', 'product_type' => 'simple', 'woocommerce_product_id' => 992001],
        );

        $this->actingAs($user)->post('/customers/orders', [
            'external_order_number' => 'LOG-OS-'.uniqid(),
            'order_date' => '2026-09-01',
            'recipient_name' => 'Test Recipient',
            'country' => 'US',
            'items' => [['product_id' => $product->id, 'product_summary' => $product->name, 'quantity' => 1, 'unit_price' => '10.00']],
        ])->assertRedirect();

        $order = Order::query()->latest('id')->firstOrFail();

        return [$user, $order, $carrier];
    }

    private function dispatch(User $user, Order $order, Carrier $carrier, ?string $tracking): void
    {
        $warehouse = Warehouse::query()->where('is_active', true)->orderBy('priority')->firstOrFail();
        $payload = [
            'warehouse_id' => $warehouse->id,
            'carrier' => $carrier->name,
            'service' => 'Ground',
            'shipped_at' => '2026-09-01T08:00:00Z',
        ];
        if ($tracking !== null) {
            $payload['tracking_number'] = $tracking;
        }
        $this->actingAs($user)->post("/logistics/orders/{$order->id}/ship", $payload)->assertRedirect();
    }

    private function userWith(array $permissions, string $roleKey = 'logistics-os-v1-'.PHP_INT_MAX): User
    {
        $role = Role::create(['key' => $roleKey.'-'.uniqid(), 'label' => 'Logistics OS Test']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
