<?php

namespace Tests\Feature\Orders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderAddressSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('inertia.testing.ensure_pages_exist', false);
    }

    public function test_source_address_sync_updates_unshipped_abc_but_not_shipped_or_replacement(): void
    {
        $editor = $this->actor(['orders.view', 'orders.edit']);
        $parent = $this->order('customer', $editor, '007-1122-c42636');
        $parent->address()->create(['recipient_name' => 'Correct Name', 'country' => 'US',
            'city' => 'New York', 'address_line_1' => '99 Updated Street', 'postal_code' => '10001']);
        $a = $this->order('fulfillment_derived', $editor, '007-1122-c42636A', $parent, 'original');
        $b = $this->order('fulfillment_derived', $editor, '007-1122-c42636B', $parent, 'original');
        $c = $this->order('fulfillment_derived', $editor, '007-1122-c42636C', $parent, 'original');
        $replacement = $this->order('fulfillment_derived', $editor, '007-1122-c42636-R1', $parent, 'replacement');
        foreach ([$a, $b, $c, $replacement] as $child) {
            $child->address()->create(['recipient_name' => 'Old Name', 'country' => 'US',
                'city' => 'Boston', 'address_line_1' => 'Old Street', 'postal_code' => '02101']);
        }
        $a->shipment()->create(['shipment_status' => 'pending']);
        $b->shipment()->create(['shipment_status' => 'pending']);
        $c->shipment()->create(['shipment_status' => 'shipped', 'tracking_number' => 'TESTTRACK']);

        $this->actingAs($editor)->get('/customers/orders/'.$parent->id)->assertOk()->assertInertia(
            fn ($page) => $page->where('canSyncChildAddresses', true)
        );
        $this->actingAs($editor)->post('/orders/'.$parent->id.'/address/sync-children')
            ->assertRedirect();
        self::assertSame('99 Updated Street', $a->fresh()->address->address_line_1);
        self::assertSame('Correct Name', $b->fresh()->address->recipient_name);
        self::assertSame('Old Street', $c->fresh()->address->address_line_1);
        self::assertSame('Old Street', $replacement->fresh()->address->address_line_1);
        self::assertSame('pending', $a->fresh()->shipment->shipment_status);
        self::assertSame('TESTTRACK', $c->fresh()->shipment->tracking_number);
    }

    public function test_direct_child_edit_only_changes_address_and_not_immutable_fulfillment_fields(): void
    {
        $editor = $this->actor(['orders.view', 'orders.edit']);
        $parent = $this->order('customer', $editor, 'SOURCE-TEST');
        $child = $this->order('fulfillment_derived', $editor, 'SOURCE-TESTA', $parent, 'original');
        $child->address()->create(['recipient_name' => 'Old Name', 'city' => 'Boston']);
        $child->shipment()->create(['shipment_status' => 'pending', 'tracking_number' => null]);
        $child->items()->create(['sku' => 'SKU-OLD', 'product_summary' => 'Test item', 'quantity' => 1,
            'unit_price' => '9.00', 'line_revenue' => '9.00']);

        $this->actingAs($editor)->patch('/orders/'.$child->id.'/address', [
            'recipient_name' => 'New Name', 'city' => 'Austin', 'address_line_1' => '101 New Street',
            'order_note' => 'should not change', 'currency' => 'EUR',
            'items' => [['sku' => 'SKU-NEW', 'quantity' => 99]],
        ])->assertRedirect();
        self::assertSame('Austin', $child->fresh()->address->city);
        self::assertSame('New Name', $child->fresh()->address->recipient_name);
        self::assertSame('USD', $child->fresh()->currency);
        self::assertNull($child->fresh()->order_note);
        self::assertSame('SKU-OLD', $child->fresh()->items()->firstOrFail()->sku);
        $this->actingAs($editor)->patch('/orders/'.$parent->id.'/address',
            ['city' => 'Madrid'])->assertNotFound();
    }

    public function test_read_only_user_cannot_update_or_sync_child_addresses(): void
    {
        $editor = $this->actor(['orders.view', 'orders.edit']);
        $viewer = $this->actor(['orders.view']);
        $parent = $this->order('customer', $editor, 'READ-ROOT');
        $child = $this->order('fulfillment_derived', $editor, 'READ-ROOTA', $parent, 'original');
        $this->actingAs($viewer)->patch('/orders/'.$child->id.'/address',
            ['city' => 'Paris'])->assertForbidden();
        $this->actingAs($viewer)->post('/orders/'.$parent->id.'/address/sync-children')
            ->assertForbidden();
    }

    public function test_logistics_preset_can_edit_order_content_without_gaining_delete_or_custom_mode_access(): void
    {
        $logistics = User::factory()->create(['is_active' => true, 'permission_mode' => 'role']);
        $role = Role::firstOrCreate(['key' => 'role_preset_logistics'], ['label' => 'Logistics']);
        foreach (['orders.view', 'logistics.view', 'logistics.operate'] as $key) {
            $role->permissions()->syncWithoutDetaching([Permission::firstOrCreate(['key' => $key], ['label' => $key])->id]);
        }
        $logistics->roles()->attach($role);
        $reader = $this->actor(['orders.view', 'logistics.view']);
        $parent = $this->order('customer', $logistics, 'LOGISTICS-EDIT');
        $child = $this->order('fulfillment_derived', $logistics, 'LOGISTICS-EDITA', $parent, 'original');
        $child->address()->create(['city' => 'Old']);
        $child->shipment()->create(['shipment_status' => 'pending']);
        $this->assertFalse($logistics->can('orders.edit'));
        $this->actingAs($logistics)->get('/customers/orders/'.$parent->id)->assertOk()->assertInertia(
            fn ($page) => $page->where('canEditOrder', true)->where('canSyncChildAddresses', true)->where('canTrashOrder', false)
        );
        $this->actingAs($logistics)->patch('/customers/orders/'.$parent->id, ['city' => 'Austin'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Austin', $parent->fresh()->address->city);
        $this->assertDatabaseHas('audit_records', ['entity_id' => (string) $parent->id, 'actor_id' => $logistics->id, 'action' => 'order.updated']);
        $this->actingAs($logistics)->post('/orders/'.$parent->id.'/address/sync-children')->assertRedirect();
        $this->assertSame('Austin', $child->fresh()->address->city);
        $this->actingAs($logistics)->patch('/orders/'.$child->id.'/address', ['city' => 'Dallas'])->assertRedirect();
        $this->assertSame('Dallas', $child->fresh()->address->city);
        $this->actingAs($logistics)->delete('/orders/'.$parent->id.'/trash')->assertForbidden();
        $this->actingAs($reader)->patch('/customers/orders/'.$parent->id, ['city' => 'Denied'])->assertForbidden();
        $this->assertSame('Austin', $parent->fresh()->address->city);
        $custom = User::factory()->create(['is_active' => true, 'permission_mode' => 'custom']);
        $custom->roles()->attach($role);
        $custom->customPermissions()->sync(Permission::whereIn('key', ['orders.view', 'logistics.operate'])->pluck('id'));
        $this->actingAs($custom)->patch('/customers/orders/'.$parent->id, ['city' => 'Denied'])->assertForbidden();
        $this->assertSame('Austin', $parent->fresh()->address->city);
        $kol = $this->order('kol_fulfillment', $logistics, 'LOGISTICS-KOL');
        $this->actingAs($logistics)->patch('/kol/orders/'.$kol->id, ['city' => 'Seattle'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Seattle', $kol->fresh()->address->city);
        $parent->update(['operational_archived_at' => now()]);
        $this->actingAs($logistics)->patch('/customers/orders/'.$parent->id, ['city' => 'Denied'])->assertStatus(409);
        $this->assertSame('Austin', $parent->fresh()->address->city);
    }

    private function actor(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'address-'.uniqid(), 'label' => 'Address Test']);
        foreach ($permissions as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        }
        $user->roles()->attach($role);
        return $user;
    }
    private function order(string $kind, User $user, string $number, ?Order $parent = null, ?string $role = null): Order
    {
        return Order::query()->create([
            'internal_order_number' => 'TEST-'.$number,
            'erp_order_id' => $number,
            'source_type' => 'manual_other',
            'order_kind' => $kind,
            'external_order_number' => 'TEST-'.$number,
            'order_date' => '2026-11-23',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
            'owner_id' => $user->id,
            'fulfillment_parent_id' => $parent?->id,
            'fulfillment_role' => $role,
            'fulfillment_suffix' => $parent ? ($role === 'replacement' ? 'R1' : substr($number, -1)) : null,
        ]);
    }
}
