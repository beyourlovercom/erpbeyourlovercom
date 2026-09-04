<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderSourceType;
use App\Modules\Orders\Application\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Procurement\Infrastructure\Models\ProcurementOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ProcurementOrderBridgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_stockout_order_can_create_one_procurement_order_with_multiple_lines(): void
    {
        $user = $this->userWith(['orders.view', 'procurement.view', 'procurement.create']);
        $order = $this->order($user, 2);
        $order->operationalMarkers()->create(['marker' => 'stockout', 'marked_by' => $user->id, 'marked_at' => now()]);

        $response = $this->actingAs($user)->post('/purchase/orders/from-order/'.$order->id, ['items' => [
            $order->items[0]->id => ['purchase_url' => 'https://shop.test/a', 'purchase_size' => 'M', 'purchase_quantity' => 3],
            $order->items[1]->id => ['purchase_url' => null, 'purchase_size' => null, 'purchase_quantity' => 1],
        ]]);

        $procurement = ProcurementOrder::query()->firstOrFail();
        $response->assertRedirect('/purchase/orders/'.$procurement->id);
        $this->assertMatchesRegularExpression('/^PO-\d{8}-\d{4}$/', $procurement->procurement_number);
        $this->assertSame($order->id, $procurement->source_order_id);
        $this->assertCount(2, $procurement->items);
        $this->assertDatabaseHas('procurement_order_items', ['source_order_item_id' => $order->items[0]->id, 'purchase_url' => 'https://shop.test/a', 'purchase_size' => 'M', 'purchase_quantity' => 3]);
    }

    public function test_non_stockout_order_cannot_create_procurement_order(): void
    {
        $user = $this->userWith(['orders.view', 'procurement.view', 'procurement.create']);
        $order = $this->order($user);
        $this->actingAs($user)->post('/purchase/orders/from-order/'.$order->id, ['items' => [
            $order->items[0]->id => ['purchase_quantity' => 1],
        ]])->assertStatus(422);
        $this->assertDatabaseCount('procurement_orders', 0);
    }

    public function test_duplicate_submission_is_idempotent_and_create_requires_permission(): void
    {
        $user = $this->userWith(['orders.view', 'procurement.view', 'procurement.create']);
        $restricted = $this->userWith(['orders.view', 'procurement.view'], 'restricted-procurement');
        $order = $this->order($user);
        $order->operationalMarkers()->create(['marker' => 'stockout', 'marked_by' => $user->id, 'marked_at' => now()]);
        $payload = ['items' => [$order->items[0]->id => ['purchase_quantity' => 1]]];
        $this->actingAs($restricted)->post('/purchase/orders/from-order/'.$order->id, $payload)->assertForbidden();
        $this->actingAs($user)->post('/purchase/orders/from-order/'.$order->id, $payload)->assertRedirect();
        $this->actingAs($user)->post('/purchase/orders/from-order/'.$order->id, $payload)->assertRedirect();
        $this->assertDatabaseCount('procurement_orders', 1);
    }

    public function test_submitted_lines_must_belong_to_the_source_order_and_cover_all_items(): void
    {
        $user = $this->userWith(['orders.view', 'procurement.view', 'procurement.create']);
        $order = $this->order($user, 2);
        $order->operationalMarkers()->create(['marker' => 'stockout', 'marked_by' => $user->id, 'marked_at' => now()]);

        $this->actingAs($user)->post('/purchase/orders/from-order/'.$order->id, ['items' => [
            $order->items[0]->id => ['purchase_quantity' => 1],
        ]])->assertStatus(422);

        $this->assertDatabaseCount('procurement_orders', 0);
    }

    public function test_procurement_detail_requires_view_permission(): void
    {
        $creator = $this->userWith(['orders.view', 'procurement.view', 'procurement.create']);
        $restricted = $this->userWith(['orders.view'], 'restricted-procurement-read');
        $order = $this->order($creator);
        $order->operationalMarkers()->create(['marker' => 'stockout', 'marked_by' => $creator->id, 'marked_at' => now()]);
        $this->actingAs($creator)->post('/purchase/orders/from-order/'.$order->id, ['items' => [
            $order->items[0]->id => ['purchase_quantity' => 1],
        ]]);
        $procurement = ProcurementOrder::query()->firstOrFail();

        $this->actingAs($restricted)->get('/purchase/orders/'.$procurement->id)->assertForbidden();
    }

    public function test_purchase_index_and_legacy_urls_use_the_canonical_purchase_contract(): void
    {
        $user = $this->userWith(['orders.view', 'procurement.view', 'procurement.create']);
        $order = $this->order($user);
        $order->operationalMarkers()->create(['marker' => 'stockout', 'marked_by' => $user->id, 'marked_at' => now()]);
        $this->actingAs($user)->post('/purchase/orders/from-order/'.$order->id, ['items' => [
            $order->items[0]->id => ['purchase_quantity' => 1],
        ]]);
        $procurement = ProcurementOrder::query()->firstOrFail();

        $this->actingAs($user)->get('/purchase')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Procurement/Orders/Index')
            ->where('orders.0.id', $procurement->id)
            ->where('orders.0.procurement_number', $procurement->procurement_number)
            ->where('orders.0.source_order_number', $order->external_order_number));
        $this->actingAs($user)->get('/purchase/orders')->assertOk();
        $this->actingAs($user)->get('/procurement')->assertRedirect('/purchase');
        $this->actingAs($user)->get('/procurement-orders')->assertRedirect('/purchase/orders');
        $this->actingAs($user)->get('/procurement-orders/'.$procurement->id)->assertRedirect('/purchase/orders/'.$procurement->id);
        $this->actingAs($user)->get('/orders/'.$order->id.'/procurement/create')->assertRedirect('/purchase/orders/from-order/'.$order->id.'/create');
    }

    private function order(User $owner, int $count = 1): Order
    {
        $order = Order::create(['internal_order_number' => 'INT-'.uniqid(), 'source_type' => OrderSourceType::Manual, 'order_kind' => OrderKind::Customer, 'external_order_number' => 'ORDER-'.uniqid(), 'order_date' => now()->toDateString(), 'owner_id' => $owner->id, 'order_status' => OrderStatus::PendingShipment]);
        for ($i = 1; $i <= $count; $i++) {
            $quantity = $i;
            $unitPrice = '10.00';
            $order->items()->create(['sku' => 'SKU-'.$i, 'product_summary' => 'Product '.$i, 'quantity' => $quantity, 'unit_price' => $unitPrice, 'line_revenue' => number_format((float) $unitPrice * $quantity, 2, '.', '')]);
        }
        return $order;
    }

    private function userWith(array $permissions, ?string $roleKey = null): User
    {
        $role = Role::create(['key' => $roleKey ?? 'procurement-'.uniqid(), 'label' => 'Procurement Test Role']);
        foreach ($permissions as $permission) $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }
}
