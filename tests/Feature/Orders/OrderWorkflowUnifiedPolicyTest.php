<?php

namespace Tests\Feature\Orders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderWorkflowStatus;
use App\Modules\Orders\Application\OrderWorkflowStatusPolicy;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class OrderWorkflowUnifiedPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_catalog_has_order_specific_actions_for_editor_viewer_and_kol(): void
    {
        $customer = $this->order(OrderKind::Customer);
        $editor = $this->user(['orders.view', 'orders.edit']);
        $viewer = $this->user(['orders.view']);
        $policy = app(OrderWorkflowStatusPolicy::class);
        $allowed = $policy->for($customer, $editor);
        $readonly = $policy->for($customer, $viewer);
        $this->assertCount(count(OrderWorkflowStatus::registry()), $allowed['options']);
        $this->assertSame(array_column($allowed['options'], 'code'), array_column($readonly['options'], 'code'));
        $this->assertTrue($allowed['can_edit']);
        $this->assertFalse($readonly['can_edit']);
        $this->assertTrue(collect($allowed['options'])->firstWhere('code', 'sending')['mutable']);
        $this->assertTrue(collect($allowed['options'])->firstWhere('code', 'out_of_stock')['mutable']);
        $this->assertFalse(collect($allowed['options'])->firstWhere('code', 'shipped')['mutable']);
        $this->assertStringContainsString('真实发货', collect($allowed['options'])->firstWhere('code', 'shipped')['disabled_reason']);
        $this->assertFalse(collect($allowed['options'])->firstWhere('code', 'arrived')['mutable']);
        $this->assertStringContainsString('真实业务事件', collect($allowed['options'])->firstWhere('code', 'arrived')['disabled_reason']);
        $this->assertNull(collect($allowed['options'])->firstWhere('code', 'sending')['disabled_reason']);
        $this->assertNotNull(collect($readonly['options'])->firstWhere('code', 'sending')['disabled_reason']);

        $kol = $this->order(OrderKind::KolFulfillment);
        $kolOptions = collect($policy->for($kol, $editor)['options'])->keyBy('code');
        $this->assertTrue($kolOptions['sending']['mutable']);
        $this->assertTrue($kolOptions['out_of_stock']['mutable']);
        $this->assertFalse($kolOptions['cancelled']['mutable']);
    }

    public function test_master_and_workflow_http_use_policy_without_fabricating_a_shipment(): void
    {
        $order = $this->order(OrderKind::Customer);
        $editor = $this->user(['orders.view', 'orders.edit']);
        $viewer = $this->user(['orders.view']);
        $this->withoutVite();
        $this->actingAs($editor)->get('/orders?search='.$order->erp_order_id)
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Master')
                ->where('orders.data.0.id', $order->id)
                ->where('orders.data.0.can_edit_workflow_status', true)
                ->where('orders.data.0.workflow_status_projection.code', 'pending_allocation')
                ->has('orders.data.0.workflow_status_options', count(OrderWorkflowStatus::registry())));
        $this->actingAs($editor)->get('/customers/orders/'.$order->id)
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Show')
                ->where('order.can_edit_workflow_status', true)
                ->where('order.workflow_status_projection.code', 'pending_allocation')
                ->has('order.workflow_status_options', count(OrderWorkflowStatus::registry())));

        $this->actingAs($editor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'out_of_stock', 'expected_workflow_revision' => 0,
        ])->assertOk()->assertJsonPath('workflow_status.code', 'out_of_stock');
        $this->assertDatabaseHas('orders', [
            'id' => $order->id, 'workflow_status' => 'out_of_stock', 'workflow_revision' => 1,
        ]);
        $this->assertDatabaseHas('order_workflow_status_histories', ['order_id' => $order->id]);
        $this->assertNull($order->fresh()->shipment);
        $this->actingAs($viewer)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'sending', 'expected_workflow_revision' => 1,
        ])->assertForbidden();
        $this->assertSame('out_of_stock', $order->fresh()->workflow_status);
        $this->assertNull($order->fresh()->shipment);
    }

    public function test_trash_archived_kol_status_is_editable_for_admin_and_restores_atomically(): void
    {
        $order = $this->order(OrderKind::KolFulfillment);
        $admin = $this->user(['orders.view', 'orders.edit', 'system.permissions.manage']);
        $editor = $this->user(['orders.view', 'orders.edit']);
        $order->forceFill(['archived_at' => now(), 'archived_by' => $admin->id])->save();
        $this->withoutVite();

        $this->actingAs($admin)->get('/orders?search='.$order->erp_order_id)
            ->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Orders/Master')
                ->where('orders.data.0.archive_state', 'trash')
                ->where('orders.data.0.can_edit_workflow_status', true)
                ->where('orders.data.0.workflow_status_projection.code', 'sending'));
        // Non-admin editors do not see trash rows in the master list and may
        // not obtain a mutable command by addressing an archived record directly.
        $this->assertFalse(app(OrderWorkflowStatusPolicy::class)->for($order, $editor)['can_edit']);

        $this->actingAs($admin)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'out_of_stock', 'expected_workflow_revision' => 0,
        ])->assertOk()->assertJsonPath('workflow_status.code', 'out_of_stock');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id, 'archived_at' => null, 'archived_by' => null,
            'order_status' => 'pending_shipment', 'workflow_status' => 'out_of_stock',
            'workflow_revision' => 1,
        ]);
        $this->assertDatabaseHas('audit_records', [
            'entity_id' => $order->id, 'action' => 'order.restored',
            'reason' => 'order_archive_status_reactivation_r41',
        ]);
        $this->assertDatabaseHas('order_workflow_status_histories', [
            'order_id' => $order->id, 'to_status' => 'out_of_stock',
        ]);
        $this->assertNull($order->fresh()->shipment);
    }

    public function test_shipped_kol_trash_archive_can_change_manual_work_state_without_faking_dispatch(): void
    {
        $order = $this->order(OrderKind::KolFulfillment);
        $admin = $this->user(['orders.view', 'orders.edit', 'system.permissions.manage']);
        $order->shipment()->create([
            'shipment_status' => 'shipped', 'shipped_at' => now(),
            'tracking_number' => 'R41-EXISTING-KOL-TRACK',
        ]);
        $order->forceFill([
            'order_status' => 'shipped', 'archived_at' => now(), 'archived_by' => $admin->id,
        ])->save();

        $this->actingAs($admin)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'renew', 'expected_workflow_revision' => 0,
        ])->assertOk()->assertJsonPath('workflow_status.code', 'renew');

        $fresh = $order->fresh();
        $this->assertNull($fresh->archived_at);
        $this->assertSame('shipped', $fresh->order_status->value);
        $this->assertSame('renew', $fresh->workflow_status);
        $this->assertSame('shipped', $fresh->shipment->shipment_status);
        $this->assertSame('R41-EXISTING-KOL-TRACK', $fresh->shipment->tracking_number);
        $this->assertNotNull($fresh->shipment->shipped_at);
        $this->assertDatabaseHas('audit_records', [
            'entity_id' => $order->id, 'action' => 'order.restored',
        ]);
    }

    public function test_trash_archive_status_edit_never_bypasses_permission_or_stale_revision(): void
    {
        $order = $this->order(OrderKind::KolFulfillment);
        $admin = $this->user(['orders.view', 'orders.edit', 'system.permissions.manage']);
        $editor = $this->user(['orders.view', 'orders.edit']);
        $viewer = $this->user(['orders.view']);
        $order->forceFill(['archived_at' => now(), 'archived_by' => $admin->id])->save();
        $payload = ['workflow_status' => 'out_of_stock', 'expected_workflow_revision' => 0];

        $this->actingAs($viewer)->patchJson('/orders/'.$order->id.'/workflow-status', $payload)
            ->assertForbidden();
        $this->actingAs($editor)->patchJson('/orders/'.$order->id.'/workflow-status', $payload)
            ->assertForbidden();
        $this->actingAs($admin)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'out_of_stock', 'expected_workflow_revision' => 1,
        ])->assertStatus(409);
        $this->actingAs($admin)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'cancelled', 'expected_workflow_revision' => 0,
            'reason' => 'No longer required',
        ])->assertStatus(409);

        $this->assertNotNull($order->fresh()->archived_at);
        $this->assertSame($admin->id, $order->fresh()->archived_by);
        $this->assertNull($order->fresh()->workflow_status);
        $this->assertSame(0, (int) $order->fresh()->workflow_revision);
        $this->assertDatabaseMissing('audit_records', [
            'entity_id' => $order->id, 'action' => 'order.restored',
        ]);
    }

    public function test_archived_child_cannot_be_restored_while_parent_is_still_in_trash(): void
    {
        $admin = $this->user(['orders.view', 'orders.edit', 'system.permissions.manage']);
        $parent = $this->order(OrderKind::KolFulfillment);
        $child = $this->order(OrderKind::KolFulfillment);
        $parent->forceFill(['archived_at' => now(), 'archived_by' => $admin->id])->save();
        $child->forceFill([
            'fulfillment_parent_id' => $parent->id,
            'archived_at' => now(), 'archived_by' => $admin->id,
        ])->save();

        $this->assertFalse(app(OrderWorkflowStatusPolicy::class)->for($child->fresh(), $admin)['can_edit']);
        $this->actingAs($admin)->patchJson('/orders/'.$child->id.'/workflow-status', [
            'workflow_status' => 'out_of_stock', 'expected_workflow_revision' => 0,
        ])->assertStatus(409);

        $this->assertNotNull($child->fresh()->archived_at);
        $this->assertNotNull($parent->fresh()->archived_at);
        $this->assertSame(0, (int) $child->fresh()->workflow_revision);
    }

    public function test_trash_archived_shipped_customer_cannot_reenter_dispatch_queue(): void
    {
        $order = $this->order(OrderKind::Customer);
        $admin = $this->user(['orders.view', 'orders.edit', 'system.permissions.manage']);
        $order->shipment()->create([
            'shipment_status' => 'shipped', 'shipped_at' => now(),
            'tracking_number' => 'R41-REAL-TRACK',
        ]);
        $order->forceFill([
            'order_status' => 'shipped', 'archived_at' => now(), 'archived_by' => $admin->id,
        ])->save();

        $this->actingAs($admin)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'sending', 'expected_workflow_revision' => 0,
        ])->assertStatus(409);

        $this->assertNotNull($order->fresh()->archived_at);
        $this->assertSame('shipped', $order->fresh()->order_status->value);
        $this->assertSame('R41-REAL-TRACK', $order->fresh()->shipment->tracking_number);
        $this->assertSame(0, (int) $order->fresh()->workflow_revision);
        $this->assertDatabaseMissing('audit_records', [
            'entity_id' => $order->id, 'action' => 'order.restored',
        ]);
    }

    private function order(OrderKind $kind): Order
    {
        return Order::query()->create([
            'erp_order_id' => 'POL-'.uniqid(),
            'internal_order_number' => 'POL-INT-'.uniqid(),
            'external_order_number' => 'POL-EXT-'.uniqid(),
            'source_type' => 'manual',
            'order_kind' => $kind,
            'order_status' => 'pending_shipment',
            'order_date' => '2026-11-20',
            'currency' => 'USD',
        ]);
    }

    private function user(array $permissions): User
    {
        $role = Role::create(['key' => 'status-policy-'.uniqid(), 'label' => 'Status Policy']);
        foreach ($permissions as $key) {
            $role->permissions()->syncWithoutDetaching([
                Permission::firstOrCreate(['key' => $key], ['label' => $key])->id,
            ]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
