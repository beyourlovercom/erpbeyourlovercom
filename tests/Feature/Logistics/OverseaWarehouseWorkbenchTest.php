<?php

namespace Tests\Feature\Logistics;

use App\Models\Role;
use App\Models\User;
use App\Modules\Inventory\Infrastructure\Models\InventorySyncBatch;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class OverseaWarehouseWorkbenchTest extends TestCase
{
    use RefreshDatabase;

    public function test_warehouse_page_is_isolated_and_uses_frozen_carrier_mapping(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $ny = $this->orderAt($warehouses['US-NY'], '007-OVERSEA-NY');
        $this->orderAt($warehouses['DE'], '007-OVERSEA-DE', 'DE');

        $this->actingAs($user)->get('/logistics/oversea/us-ny?view=review')->assertInertia(fn (Assert $page) => $page
            ->component('Logistics/Oversea/Show')
            ->where('warehouse.label', '美东纽约')
            ->where('rows.total', 1)
            ->where('rows.data.0.order_id', $ny->id)
            ->where('rows.data.0.line', 1)
            ->where('rows.data.0.carrier', 'USPS')
            ->where('rows.data.0.review_status', 'pending'));
    }

    public function test_approval_moves_order_from_review_to_pending_and_persists_default_carrier(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['DE'], '007-OVERSEA-APPROVE', 'DE');

        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve")->assertRedirect();

        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $order->id,
            'status' => 'approved',
            'reviewed_warehouse_id' => $warehouses['DE']->id,
        ]);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'warehouse_id' => $warehouses['DE']->id,
            'carrier' => 'DHL',
            'shipment_status' => 'pending',
        ]);

        $this->actingAs($user)->get('/logistics/oversea/de-tianyun?view=pending')->assertInertia(
            fn (Assert $page) => $page->where('rows.total', 1)->where('rows.data.0.order_id', $order->id)
        );
        $this->actingAs($user)->get('/logistics/oversea/de-tianyun?view=review')->assertInertia(
            fn (Assert $page) => $page->where('rows.total', 0)
        );
    }

    public function test_approved_order_can_be_reopened_for_review_and_reassignment(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007-OVERSEA-REOPEN');

        $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/approve", ['note' => '审核通过'])->assertRedirect();
        $response = $this->actingAs($user)->post("/logistics/oversea/orders/{$order->id}/reopen-review");
        $response->assertRedirect('/logistics/oversea/us-moreplus?view=review');

        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $order->id, 'status' => 'pending', 'progress' => 'pending_review',
            'reviewed_warehouse_id' => null, 'reviewed_by' => null, 'reviewed_at' => null,
        ]);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id, 'warehouse_id' => $warehouses['US-LA']->id, 'carrier' => 'USPS',
        ]);
    }

    public function test_dede_can_change_warehouse_and_carrier_follows_the_new_warehouse(): void
    {
        $user = $this->userWith(['logistics.view', 'logistics.operate']);
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-LA'], '007-OVERSEA-CHANGE');

        $this->actingAs($user)->patch("/logistics/oversea/orders/{$order->id}/draft", [
            'warehouse_id' => $warehouses['CA']->id,
            'note' => 'Vita 仓库选择错误',
        ])->assertRedirect();

        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'warehouse_id' => $warehouses['CA']->id,
            'carrier' => 'Canada Post',
        ]);
        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $order->id,
            'submitted_warehouse_id' => $warehouses['US-LA']->id,
            'status' => 'pending',
            'note' => 'Vita 仓库选择错误',
        ]);
    }

    public function test_rejection_requires_reason_clears_assignment_and_notifies_all_operations_users(): void
    {
        $dede = $this->userWith(['logistics.view', 'logistics.operate']);
        $opsA = $this->operationsUser('vita-a@example.test');
        $opsB = $this->operationsUser('vita-b@example.test');
        $warehouses = $this->warehouses();
        $order = $this->orderAt($warehouses['US-NY'], '007-OVERSEA-REJECT');

        $this->actingAs($dede)->post("/logistics/oversea/orders/{$order->id}/reject", [])->assertSessionHasErrors('note');
        $this->actingAs($dede)->post("/logistics/oversea/orders/{$order->id}/reject", ['note' => '太贵，退回重新分仓'])->assertRedirect();

        $this->assertDatabaseHas('oversea_warehouse_reviews', [
            'order_id' => $order->id,
            'status' => 'rejected',
            'note' => '太贵，退回重新分仓',
        ]);
        $this->assertDatabaseHas('shipments', [
            'order_id' => $order->id,
            'warehouse_id' => null,
            'warehouse_selection_source' => null,
            'carrier' => null,
            'carrier_selection_source' => null,
        ]);

        foreach ([$opsA, $opsB] as $ops) {
            $notification = $ops->notifications()->first();
            $this->assertNotNull($notification);
            $this->assertSame('海外仓分配被驳回', $notification->data['title']);
            $this->assertSame('/logistics/wait-stock', $notification->data['action_url']);
            $this->assertStringContainsString('太贵，退回重新分仓', $notification->data['message']);
        }
    }

    public function test_view_only_user_sees_page_but_not_operate_flag(): void
    {
        $user = $this->userWith(['logistics.view']);
        $warehouses = $this->warehouses();
        $this->orderAt($warehouses['US-NY'], '007-OVERSEA-VIEW');
        $this->actingAs($user)->get('/logistics/oversea/us-ny')->assertInertia(fn (Assert $page) => $page->where('canOperate', false));
    }

    /** @return array<string,Warehouse> */
    private function warehouses(): array
    {
        $rows = [
            'US-NY' => ['us-ny', '美东纽约仓'],
            'US-LA' => ['us-la', '美西洛杉矶仓'],
            'US-SJ' => ['us-sj', '美西世迈仓'],
            'DE' => ['de', '德国仓'],
            'CA' => ['ca', '加拿大仓库'],
        ];
        $result = [];
        foreach ($rows as $code => [$slug, $name]) {
            $result[$code] = Warehouse::query()->updateOrCreate(['code' => $code], [
                'slug' => $slug,
                'name' => $name,
                'type' => 'warehouse',
                'is_active' => true,
                'priority' => 10,
            ]);
        }
        Warehouse::query()->updateOrCreate(['code' => '007'], ['slug' => '007', 'name' => '007本地仓库', 'type' => 'warehouse', 'is_active' => true, 'priority' => 99]);
        Warehouse::query()->updateOrCreate(['code' => 'FACTORY'], ['slug' => 'factory', 'name' => '中国工厂', 'type' => 'warehouse', 'is_active' => true, 'priority' => 98]);
        $product = Product::query()->firstOrCreate(['sku' => 'REVIEW-SKU'], ['name' => 'Review Product', 'product_type' => 'simple', 'is_active' => true]);
        foreach ($result as $warehouse) {
            InventorySyncBatch::query()->create(['warehouse_id' => $warehouse->id, 'source_type' => 'api', 'sync_mode' => 'full', 'status' => 'confirmed', 'confirmed_at' => now()]);
            WarehouseInventory::query()->updateOrCreate(['warehouse_id' => $warehouse->id, 'product_id' => $product->id], ['quantity_on_hand' => 100, 'quantity_reserved' => 0]);
        }
        return $result;
    }

    private function orderAt(Warehouse $warehouse, string $erpOrderId, string $country = 'US'): Order
    {
        $order = Order::query()->create([
            'internal_order_number' => 'INT-'.uniqid(),
            'erp_order_id' => $erpOrderId,
            'source_type' => 'manual_other',
            'order_kind' => 'customer',
            'external_order_number' => 'SOURCE-'.uniqid(),
            'order_date' => '2026-09-10',
            'order_status' => 'pending_shipment',
        ]);
        $order->address()->create([
            'recipient_name' => 'Review Recipient', 'phone' => '+1 555 0100', 'country' => $country,
            'province_state' => 'CA', 'city' => 'City', 'address_line_1' => '1 Main St', 'postal_code' => '90001',
        ]);
        $order->items()->create([
            'product_id' => Product::query()->where('sku', 'REVIEW-SKU')->value('id'),
            'product_summary' => 'Review Product', 'sku' => 'REVIEW-SKU', 'quantity' => 2,
            'unit_price' => '10.00', 'line_revenue' => '20.00',
        ]);
        $order->shipment()->create([
            'shipment_status' => 'pending', 'warehouse_id' => $warehouse->id,
            'warehouse_selection_source' => 'manual',
        ]);
        return $order->fresh(['address', 'items', 'shipment.warehouse']);
    }
    private function userWith(array $permissions): User
    {
        $role = Role::query()->create([
            'key' => 'oversea-test-'.uniqid(),
            'label' => 'Oversea Test',
        ]);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(
                ['key' => $permission],
                ['label' => $permission],
            );
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }

    private function operationsUser(string $email): User
    {
        $role = Role::query()->firstOrCreate([
            'key' => 'role_preset_operations',
        ], ['label' => '运营']);
        $user = User::factory()->create(['email' => $email, 'is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}