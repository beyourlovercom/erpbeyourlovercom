<?php

namespace Tests\Feature\Orders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Orders\Application\OrderWorkflowStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderWorkflowStatusV1Test extends TestCase
{
    use RefreshDatabase;

    public function test_registry_keeps_duplicate_return_labels_distinct_and_authority_states_read_only(): void
    {
        self::assertSame('返回订单', OrderWorkflowStatus::registry()['returned']['zh']);
        self::assertSame('退件需跟进', OrderWorkflowStatus::registry()['returned_follow_up']['zh']);
        self::assertFalse(OrderWorkflowStatus::registry()['shipping']['mutable']);
        self::assertFalse(OrderWorkflowStatus::registry()['paid']['mutable']);
        self::assertSame('shipped', OrderWorkflowStatus::effectiveCode('sent', 'shipped'));
        self::assertSame('finished', OrderWorkflowStatus::effectiveCode('finished', 'completed'));
        self::assertSame('creating', OrderWorkflowStatus::effectiveCode('creating', 'shipped'));
        self::assertSame('cancelled', OrderWorkflowStatus::effectiveCode('finished', 'cancelled'));
    }

    public function test_first_ui_supported_change_requires_the_revision_when_database_status_is_null(): void
    {
        $editor = $this->userWith(['orders.view', 'orders.edit']);
        $order = $this->order($editor);

        $response = $this->actingAs($editor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'missing_info',
        ])->getStatusCode();
        self::assertSame(302, $response);
        self::assertNull($order->fresh()->workflow_status);

        $this->actingAs($editor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'missing_info', 'expected_workflow_revision' => 0,
        ])->assertOk()->assertJsonPath('workflow_status.code', 'missing_info');
        self::assertSame(1, $order->fresh()->workflow_revision);
    }

    public function test_editor_changes_only_workflow_status_and_writes_one_history_row(): void
    {
        $editor = $this->userWith(['orders.view', 'orders.edit']);
        $order = $this->order($editor);

        $this->actingAs($editor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'missing_info', 'expected_workflow_revision' => 0,
        ])->assertOk()->assertJsonPath('workflow_status.code', 'missing_info');

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'order_status' => 'pending_shipment', 'workflow_status' => 'missing_info', 'workflow_revision' => 1]);
        $this->assertDatabaseCount('order_workflow_status_histories', 1);
        $this->assertDatabaseHas('audit_records', ['entity_id' => (string) $order->id, 'action' => 'order.workflow_status_changed']);
    }

    public function test_same_status_is_idempotent_and_stale_revision_is_conflict(): void
    {
        $editor = $this->userWith(['orders.view', 'orders.edit']);
        $order = $this->order($editor);
        $payload = ['workflow_status' => 'sending', 'expected_workflow_revision' => 0];

        $this->actingAs($editor)->patchJson('/orders/'.$order->id.'/workflow-status', $payload)->assertOk();
        $this->actingAs($editor)->patchJson('/orders/'.$order->id.'/workflow-status', $payload)->assertOk();
        self::assertSame(0, $order->fresh()->workflow_revision);
        $this->assertDatabaseCount('order_workflow_status_histories', 0);

        $this->actingAs($editor)->patchJson('/orders/'.$order->id.'/workflow-status', ['workflow_status' => 'resent', 'expected_workflow_revision' => 9])->assertStatus(409);
    }

    public function test_reader_and_kol_outsider_cannot_mutate_inaccessible_order(): void
    {
        $reader = $this->userWith(['orders.view']);
        $order = $this->order($reader);
        $this->actingAs($reader)->patchJson('/orders/'.$order->id.'/workflow-status', ['workflow_status' => 'renew'])->assertForbidden();

        $owner = $this->userWith(['orders.view', 'orders.edit', 'kol.view']);
        $outsider = $this->userWith(['orders.view', 'orders.edit', 'kol.view']);
        $kolOrder = $this->order($owner, 'kol_fulfillment');
        $this->actingAs($outsider)->patchJson('/orders/'.$kolOrder->id.'/workflow-status', ['workflow_status' => 'renew'])->assertForbidden();
    }

    public function test_archived_orders_reject_state_changes_without_modifying_canonical_or_history(): void
    {
        $editor = $this->userWith(['orders.view', 'orders.edit']);
        $order = $this->order($editor);
        $order->forceFill(['archived_at' => now(), 'archived_by' => $editor->id])->save();

        $this->actingAs($editor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'renew', 'expected_workflow_revision' => 0,
        ])->assertStatus(422);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id, 'order_status' => 'pending_shipment',
            'workflow_status' => null, 'workflow_revision' => 0,
        ]);
        $this->assertDatabaseCount('order_workflow_status_histories', 0);
    }

    public function test_authoritative_paid_label_cannot_be_written_without_payment_evidence(): void
    {
        $editor = $this->userWith(['orders.view', 'orders.edit']);
        $order = $this->order($editor);
        $response = $this->actingAs($editor)->patchJson('/orders/'.$order->id.'/workflow-status', [
            'workflow_status' => 'paid', 'expected_workflow_revision' => 0,
        ]);
        self::assertNotSame(200, $response->getStatusCode());
        self::assertNull($order->fresh()->workflow_status);
        $this->assertDatabaseCount('order_workflow_status_histories', 0);
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'workflow-test-'.uniqid(), 'label' => 'Workflow Test']);
        foreach ($permissions as $permission) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $permission], ['label' => $permission]));
        }
        $user->roles()->attach($role);
        return $user;
    }

    private function order(User $owner, string $kind = 'customer'): Order
    {
        return Order::create([
            'internal_order_number' => 'WORKFLOW-'.uniqid(), 'erp_order_id' => 'WORKFLOW-'.uniqid(),
            'source_type' => 'manual', 'order_kind' => $kind, 'external_order_number' => 'WORKFLOW-'.uniqid(),
            'order_date' => '2026-11-01', 'order_status' => 'pending_shipment', 'currency' => 'USD', 'owner_id' => $owner->id,
        ]);
    }
}
