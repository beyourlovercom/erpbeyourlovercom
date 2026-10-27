<?php

namespace Tests\Feature\Orders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        // Active Woo/integration references must not become hidden or orphaned.
        $this->actingAs($admin)->delete('/orders/'.$order->id.'/trash')->assertStatus(409);
        $this->assertNotSoftDeleted('orders', ['id' => $order->id]);

        // Even a legacy trash row is not eligible for unsafe physical deletion.
        $order->delete();
        $this->actingAs($admin)->delete('/orders/trash/'.$order->id)->assertStatus(409);
        $this->assertSoftDeleted('orders', ['id' => $order->id]);
        $this->assertDatabaseHas('order_external_references', ['order_id' => $order->id]);
    }

    public function test_shipment_and_financial_evidence_prevent_soft_delete(): void
    {
        $employee = $this->userWith(['orders.view', 'orders.edit']);
        $shipmentOrder = $this->order($employee, 'customer', 'shipment');
        $shipmentOrder->shipment()->create(['shipment_status' => 'pending']);
        $this->actingAs($employee)->delete('/orders/'.$shipmentOrder->id.'/trash')->assertStatus(409);
        $this->assertNotSoftDeleted('orders', ['id' => $shipmentOrder->id]);

        $financialOrder = $this->order($employee, 'customer', 'finance');
        $financialOrder->financials()->create(['currency' => 'USD']);
        $this->actingAs($employee)->delete('/orders/'.$financialOrder->id.'/trash')->assertStatus(409);
        $this->assertNotSoftDeleted('orders', ['id' => $financialOrder->id]);
        $this->assertDatabaseHas('order_financials', ['order_id' => $financialOrder->id]);
    }

    public function test_reserved_inventory_and_fulfillment_children_prevent_soft_delete(): void
    {
        $employee = $this->userWith(['orders.view', 'orders.edit']);
        $reserved = $this->order($employee, 'customer', 'reserved');
        $warehouseId = DB::table('warehouses')->insertGetId([
            'slug' => 'trash-uat-warehouse', 'code' => 'TRASH-UAT', 'name' => 'Trash UAT Warehouse',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventory_reservations')->insert([
            'reservation_key' => 'trash-uat-'.$reserved->id,
            'payload_hash' => str_repeat('a', 64),
            'order_id' => $reserved->id,
            'warehouse_id' => $warehouseId,
            'status' => 'reserved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($employee)->delete('/orders/'.$reserved->id.'/trash')->assertStatus(409);
        $this->assertNotSoftDeleted('orders', ['id' => $reserved->id]);
        $this->assertDatabaseHas('inventory_reservations', ['order_id' => $reserved->id]);

        $parent = $this->order($employee, 'customer', 'parent');
        $child = $this->order($employee, 'customer', 'child');
        $child->fulfillment_parent_id = $parent->id;
        $child->save();
        $this->actingAs($employee)->delete('/orders/'.$parent->id.'/trash')->assertStatus(409);
        $this->actingAs($employee)->delete('/orders/'.$child->id.'/trash')->assertStatus(409);
        $this->assertNotSoftDeleted('orders', ['id' => $parent->id]);
        $this->assertNotSoftDeleted('orders', ['id' => $child->id]);
    }

    public function test_orders_view_permission_is_also_required_for_trash(): void
    {
        $editorWithoutRead = $this->userWith(['orders.edit']);
        $order = $this->order($editorWithoutRead, 'customer', 'blind-edit');
        $this->actingAs($editorWithoutRead)->delete('/orders/'.$order->id.'/trash')->assertForbidden();
        $this->assertNotSoftDeleted('orders', ['id' => $order->id]);
    }

    public function test_fulfilled_orders_cannot_be_hidden_even_without_shipment_row(): void
    {
        $employee = $this->userWith(['orders.view', 'orders.edit']);
        $order = $this->order($employee, 'customer', 'shipped');
        $order->order_status = 'shipped';
        $order->save();
        $this->actingAs($employee)->delete('/orders/'.$order->id.'/trash')->assertStatus(409);
        $this->assertNotSoftDeleted('orders', ['id' => $order->id]);
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
