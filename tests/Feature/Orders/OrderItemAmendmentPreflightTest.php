<?php

namespace Tests\Feature\Orders;

use App\Models\Role;
use App\Models\User;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderItemAmendmentPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_woo_combo_split_can_preview_then_save_without_changing_total_or_untouched_identity(): void
    {
        [$order, $combo, $a1, $mf, $untouched] = $this->fixture();
        $actor = $this->actor(['orders.view', 'orders.edit']);
        $items = [
            ['id' => $untouched->id, 'product_id' => $untouched->product_id, 'quantity' => 1, 'unit_price' => '39.60', 'product_summary' => 'ALAN'],
            ['product_id' => $a1->id, 'quantity' => 1, 'unit_price' => '90.00', 'product_summary' => ''],
            ['product_id' => $mf->id, 'quantity' => 1, 'unit_price' => '9.20', 'product_summary' => ''],
        ];
        $preview = $this->actingAs($actor)->postJson("/customers/orders/{$order->id}/items/preview", ['items' => $items])
            ->assertOk()->assertJsonPath('changed', true)->assertJsonPath('can_save_direct', true)
            ->assertJsonPath('fulfillment_only', true)->assertJsonPath('gross_delta', '0.00')
            ->assertJsonPath('no_writes', true)->json();
        $this->assertCount(2, $order->items()->get());

        $this->actingAs($actor)->patch("/customers/orders/{$order->id}", [
            'items' => $items, 'expected_revision' => $preview['expected_revision'],
            'amendment_reason' => '同意将组合 SKU 拆分为两个实物 SKU',
        ])->assertRedirect();
        $this->assertSame(['A1', 'MF414A', 'VG515A'], $order->items()->orderBy('sku')->pluck('sku')->all());
        $this->assertSame(1, $order->items()->where('id', $untouched->id)->count());
        $this->assertSame(77701, $order->items()->where('id', $untouched->id)->value('woocommerce_line_item_id'));
    }

    public function test_woo_split_with_changed_total_is_blocked_and_does_not_touch_rows(): void
    {
        [$order, $combo, $a1, $mf] = $this->fixture();
        $actor = $this->actor(['orders.view', 'orders.edit']);
        $items = [
            ['product_id' => $a1->id, 'quantity' => 1, 'unit_price' => '90.00'],
            ['product_id' => $mf->id, 'quantity' => 1, 'unit_price' => '0.00'],
        ];
        $this->actingAs($actor)->postJson("/customers/orders/{$order->id}/items/preview", ['items' => $items])
            ->assertOk()->assertJsonPath('can_save_direct', false)->assertJsonPath('requires_replan', false);
        $this->actingAs($actor)->patch("/customers/orders/{$order->id}", ['items' => $items])
            ->assertSessionHasErrors('items');
        $this->assertSame(2, $order->items()->count());
    }

    public function test_allocated_order_returns_blocked_preview_and_never_overwrites_items(): void
    {
        [$order, $combo, $a1] = $this->fixture();
        $order->fulfillmentAllocation()->create(['status' => 'submitted', 'decision_source' => 'manual']);
        $actor = $this->actor(['orders.view', 'orders.edit']);
        $items = [['product_id' => $a1->id, 'quantity' => 1, 'unit_price' => '138.80']];
        $this->actingAs($actor)->postJson("/customers/orders/{$order->id}/items/preview", ['items' => $items])
            ->assertOk()->assertJsonPath('can_save_direct', false)->assertJsonPath('requires_replan', true)
            ->assertJsonPath('can_atomic_single_replan', false);
        $this->actingAs($actor)->patch("/customers/orders/{$order->id}", ['items' => $items])
            ->assertSessionHasErrors('items');
        $this->assertSame(2, $order->items()->count());
    }

    public function test_read_only_staff_cannot_use_preview_endpoint(): void
    {
        [$order, $combo, $a1] = $this->fixture();
        $actor = $this->actor(['orders.view']);
        $this->actingAs($actor)->postJson("/customers/orders/{$order->id}/items/preview", [
            'items' => [['product_id' => $a1->id, 'quantity' => 1, 'unit_price' => '9.00']],
        ])->assertForbidden();
    }

    private function fixture(): array
    {
        $combo = Product::create(['sku' => 'MF414A & A1', 'name' => 'Original Combo', 'product_type' => 'simple', 'woocommerce_product_id' => 71001]);
        $a1 = Product::create(['sku' => 'A1', 'name' => 'A1', 'product_type' => 'simple', 'woocommerce_product_id' => 71002]);
        $mf = Product::create(['sku' => 'MF414A', 'name' => 'MF414A', 'product_type' => 'simple', 'woocommerce_product_id' => 71003]);
        $vg = Product::create(['sku' => 'VG515A', 'name' => 'VG515A', 'product_type' => 'simple', 'woocommerce_product_id' => 71004]);
        $order = Order::create([
            'order_kind' => 'customer', 'source_type' => 'woocommerce', 'order_status' => 'pending_shipment',
            'order_date' => now()->toDateString(), 'internal_order_number' => 'TEST-7870',
            'erp_order_id' => 'TEST-7870', 'external_order_number' => 'TEST-7870', 'currency' => 'USD',
        ]);
        $order->items()->create(['product_id' => $combo->id, 'sku' => $combo->sku, 'product_summary' => 'Original Combo', 'quantity' => 1, 'unit_price' => '99.20', 'line_revenue' => '99.20', 'woocommerce_line_item_id' => 77700]);
        $untouched = $order->items()->create(['product_id' => $vg->id, 'sku' => $vg->sku, 'product_summary' => 'ALAN', 'quantity' => 1, 'unit_price' => '39.60', 'line_revenue' => '39.60', 'woocommerce_line_item_id' => 77701]);

        return [$order, $combo, $a1, $mf, $untouched];
    }

    private function actor(array $permissions): User
    {
        $role = Role::create(['key' => 'preflight-'.uniqid(), 'label' => 'preflight']);
        foreach ($permissions as $permission) {
            $role->permissions()->firstOrCreate(['key' => $permission], ['label' => $permission]);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }
}
