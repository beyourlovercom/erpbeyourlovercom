<?php

namespace Tests\Feature\Orders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderTrashV1Test extends TestCase
{
    use RefreshDatabase;

    public function test_editor_can_trash_order_only_through_detail_action_and_admin_can_restore(): void
    {
        $editor = $this->userWith(['orders.view', 'orders.edit']);
        $admin = $this->userWith(['orders.view', 'orders.edit', 'system.permissions.manage']);
        $order = $this->order($editor, 'customer', 'restore');

        $this->actingAs($editor)->get('/customers/orders/'.$order->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('canTrashOrder', true)->where('canManageTrash', false));
        $this->actingAs($editor)->delete('/orders/'.$order->id.'/trash')->assertRedirect('/orders');
        $this->assertSoftDeleted('orders', ['id' => $order->id, 'deleted_by' => $editor->id]);
        $this->actingAs($editor)->get('/customers/orders/'.$order->id)->assertNotFound();
        $this->actingAs($editor)->get('/orders')->assertOk()
            ->assertInertia(fn ($page) => $page->has('orders.data', 0));
        $this->actingAs($editor)->get('/orders/trash')->assertForbidden();
        $this->actingAs($editor)->post('/orders/trash/'.$order->id.'/restore')->assertForbidden();
        $this->actingAs($editor)->delete('/orders/trash/'.$order->id)->assertForbidden();

        $this->actingAs($admin)->get('/orders/trash')->assertOk()
            ->assertInertia(fn ($page) => $page->has('orders.data', 1)->where('orders.data.0.id', $order->id));
        $this->actingAs($admin)->post('/orders/trash/'.$order->id.'/restore')->assertRedirect();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'deleted_at' => null]);
        $this->actingAs($editor)->get('/customers/orders/'.$order->id)->assertOk();
    }

    public function test_only_users_with_order_edit_permission_can_trash(): void
    {
        $reader = $this->userWith(['orders.view']);
        $order = $this->order($reader, 'customer', 'readonly');
        $this->actingAs($reader)->delete('/orders/'.$order->id.'/trash')->assertForbidden();
        $this->actingAs($reader)->get('/customers/orders/'.$order->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('canTrashOrder', false));
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'deleted_at' => null]);
    }

    public function test_kol_owner_scope_is_enforced_for_trash(): void
    {
        $specialist = $this->userWith(['orders.view', 'orders.edit', 'kol.view']);
        $other = $this->userWith(['orders.view', 'orders.edit', 'kol.view']);
        $order = $this->order($other, 'kol_fulfillment', 'foreignkol');
        $this->actingAs($specialist)->delete('/orders/'.$order->id.'/trash')->assertForbidden();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'deleted_at' => null]);
    }

    public function test_permanent_deletion_blocked_when_external_reference_exists(): void
    {
        $admin = $this->userWith(['orders.view', 'orders.edit', 'system.permissions.manage']);
        $order = $this->order($admin, 'customer', 'external');
        $order->externalReferences()->create(['provider' => 'test', 'reference' => 'TRASH-V1-REF']);
        $this->actingAs($admin)->delete('/orders/'.$order->id.'/trash')->assertRedirect();
        $this->actingAs($admin)->delete('/orders/trash/'.$order->id)->assertStatus(409);
        $this->assertSoftDeleted('orders', ['id' => $order->id]);
        $this->assertDatabaseHas('order_external_references', ['order_id' => $order->id]);
    }

    private function userWith(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'trash_uat_'.uniqid(), 'label' => 'Trash UAT']);
        foreach ($keys as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        }
        $user->roles()->attach($role);
        return $user;
    }

    private function order(User $owner, string $kind, string $suffix): Order
    {
        return Order::create([
            'internal_order_number' => 'TRASH-'.strtoupper($suffix),
            'erp_order_id' => '007erp-1027-k'.random_int(1000000, 9999999),
            'source_type' => 'manual',
            'order_kind' => $kind,
            'external_order_number' => 'TRASH-UAT-'.$suffix,
            'order_date' => '2026-10-27',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
            'owner_id' => $owner->id,
        ]);
    }
}
