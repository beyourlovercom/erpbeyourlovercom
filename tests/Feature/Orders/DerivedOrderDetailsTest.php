<?php

namespace Tests\Feature\Orders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DerivedOrderDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
    }

    public function test_customer_derived_order_opens_own_detail_and_preserves_source_link_without_edit_permissions(): void
    {
        $viewer = $this->userWith(['orders.view', 'orders.edit', 'logistics.view', 'logistics.operate', 'analysis.view']);
        $root = $this->order('customer', $viewer, '007-1102-TEST');
        $child = $this->order('fulfillment_derived', $viewer, '007-1102-TESTB', $root);

        $child->address()->create([
            'recipient_name' => 'Synthetic Recipient B', 'country' => 'US',
            'city' => 'Boston', 'address_line_1' => '20 Test Street', 'postal_code' => '02101',
        ]);
        $child->items()->create([
            'sku' => 'TEST-SPLIT-SKU-B', 'product_summary' => 'Synthetic Product B',
            'quantity' => 1, 'unit_price' => '12.00', 'line_revenue' => '12.00',
        ]);
        $child->shipment()->create(['shipment_status' => 'pending']);

        $this->actingAs($viewer)->get('/customers/orders/'.$child->id)->assertOk()->assertInertia(
            fn ($page) => $page
                ->component('Orders/Show')
                ->where('order.id', $child->id)
                ->where('order.erp_order_id', '007-1102-TESTB')
                ->where('order.order_kind', 'fulfillment_derived')
                ->where('order.items.0.sku', 'TEST-SPLIT-SKU-B')
                ->where('order.address.recipient_name', 'Synthetic Recipient B')
                ->where('order.fulfillment_parent.id', $root->id)
                ->where('order.fulfillment_parent.erp_order_id', '007-1102-TEST')
                ->where('order.fulfillment_parent.url', '/customers/orders/'.$root->id)
                ->where('canEditOrder', false)
                ->where('canTrashOrder', false)
                ->where('canOperateLogistics', true)
                ->where('canReopenFulfillment', true)
                ->where('canViewProfit', false)
                ->where('orderNavigation.previous_url', null)
                ->where('orderNavigation.next_url', null)
        );

        $this->actingAs($viewer)->get('/orders/'.$child->id)
            ->assertRedirect('/customers/orders/'.$child->id);
        $this->actingAs($viewer)->patch('/customers/orders/'.$child->id, ['order_note' => 'Not allowed'])
            ->assertNotFound();
        self::assertNull($child->fresh()->order_note);
        $this->actingAs($viewer)->get('/customers/orders/'.$root->id)->assertOk();
    }

    public function test_kol_derived_detail_inherits_parent_ownership_and_stays_out_of_customer_route(): void
    {
        $owner = $this->userWith(['orders.view', 'kol.view', 'kol.operate']);
        $other = $this->userWith(['orders.view', 'kol.view', 'kol.operate']);
        $root = $this->order('kol_fulfillment', $owner, '007-KOL-ROOT');
        $child = $this->order('fulfillment_derived', $other, '007-KOL-ROOTA', $root);

        $this->actingAs($other)->get('/kol/orders/'.$child->id)->assertForbidden();
        $this->actingAs($other)->get('/orders/'.$child->id)->assertForbidden();
        $this->actingAs($owner)->get('/customers/orders/'.$child->id)->assertNotFound();
        $this->actingAs($owner)->get('/orders/'.$child->id)
            ->assertRedirect('/kol/orders/'.$child->id);
        $this->actingAs($owner)->get('/kol/orders/'.$child->id)->assertOk()->assertInertia(
            fn ($page) => $page
                ->where('order.id', $child->id)
                ->where('order.fulfillment_parent.order_kind', 'kol_fulfillment')
                ->where('order.fulfillment_parent.url', '/kol/orders/'.$root->id)
                ->where('canEditOrder', false)
        );
    }

    public function test_archived_source_blocks_derived_detail_even_if_segment_remains_active(): void
    {
        $reader = $this->userWith(['orders.view']);
        $root = $this->order('customer', $reader, '007-ARCHIVED-ROOT');
        $child = $this->order('fulfillment_derived', $reader, '007-ARCHIVED-ROOTA', $root);
        $root->forceFill(['archived_at' => now()])->save();

        $this->actingAs($reader)->get('/customers/orders/'.$child->id)->assertNotFound();
        $this->actingAs($reader)->get('/orders/'.$child->id)->assertNotFound();
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'derived-detail-'.uniqid(), 'label' => 'Derived Detail Test']);
        foreach ($permissions as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        }
        $user->roles()->attach($role);

        return $user;
    }

    private function order(string $kind, User $owner, string $number, ?Order $parent = null): Order
    {
        return Order::query()->create([
            'internal_order_number' => 'TEST-'.$number,
            'erp_order_id' => $number,
            'source_type' => 'manual_other',
            'order_kind' => $kind,
            'external_order_number' => 'TEST-EXTERNAL-'.$number,
            'order_date' => '2026-11-03',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
            'owner_id' => $owner->id,
            'fulfillment_parent_id' => $parent?->id,
            'fulfillment_role' => $parent ? 'original' : null,
            'fulfillment_suffix' => $parent ? 'A' : null,
        ]);
    }
}
