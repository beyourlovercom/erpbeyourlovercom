<?php

namespace Tests\Feature\Orders;

use App\Modules\Identity\Application\RolePreset;
use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderContactCreationTest extends TestCase
{
    use RefreshDatabase;

    private function staff(RolePreset $preset, bool $active = true): User
    {
        $role = Role::firstOrCreate(['key' => $preset->roleKey()], ['label' => $preset->label()]);
        foreach ($preset->permissions() as $key) {
            $role->permissions()->syncWithoutDetaching([Permission::firstOrCreate(['key' => $key], ['label' => $key])->id]);
        }
        $user = User::factory()->create(['is_active' => $active]);
        $user->roles()->attach($role);

        return $user;
    }

    public function test_kol_contact_is_required_and_must_be_eligible_without_changing_order_owner(): void
    {
        $this->withoutVite();
        $creator = $this->staff(RolePreset::KolSpecialist);
        $contact = $this->staff(RolePreset::KolManager);
        $operations = $this->staff(RolePreset::Operations);
        $logistics = $this->staff(RolePreset::Logistics);
        $inactive = $this->staff(RolePreset::KolSpecialist, false);
        $kol = Kol::create(['display_name' => 'Synthetic KOL', 'owner_id' => $creator->id, 'status' => 'active', 'relationship_stage' => 'cooperating']);
        $product = Product::create(['source_type' => 'manual', 'sku' => 'SYN-CONTACT-01', 'name' => 'Synthetic product', 'is_active' => true]);
        $payload = [
            'external_order_number' => 'SYN-CONTACT-ORDER', 'kol_id' => $kol->id,
            'order_date' => '2026-11-24', 'currency' => 'USD',
            'recipient_name' => 'Synthetic Recipient', 'phone' => '10001', 'country' => 'US',
            'province_state' => 'CA', 'city' => 'Los Angeles', 'address_line_1' => 'Synthetic Street',
            'items' => [['product_id' => $product->id, 'product_summary' => 'Synthetic product', 'sku' => 'SYN-CONTACT-01', 'quantity' => 1, 'unit_price' => '0.00']],
        ];
        $this->actingAs($creator)->get('/kol/orders/create?kol_id='.$kol->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('selectedKolContactId', $creator->id)->has('contactOptions', 2));
        $this->post('/kol/orders', $payload)->assertSessionHasErrors('contact_user_id');
        foreach ([$operations, $logistics, $inactive] as $ineligible) {
            $this->post('/kol/orders', [...$payload, 'contact_user_id' => $ineligible->id])->assertSessionHasErrors('contact_user_id');
        }
        $this->assertDatabaseCount('orders', 0);
        $this->post('/kol/orders', [...$payload, 'contact_user_id' => $contact->id])->assertRedirect();
        $order = Order::query()->firstOrFail();
        self::assertEquals($creator->id, $order->owner_id);
        self::assertEquals($contact->id, $order->contact_user_id);
        $projection = new \App\Modules\Orders\Application\OrderContactProjection();
        self::assertEquals($contact->id, $projection->for($order)['contact_user_id']);
        $query = Order::query();
        $projection->applyNameFilter($query, $contact->name);
        self::assertEquals([$order->id], $query->pluck('id')->all());

        $this->get('/kol/orders/'.$order->id)->assertOk()
            ->assertInertia(fn ($page) => $page->has('contactOptions', 4)
                ->where('canEditContact', true));
        $this->patch('/orders/'.$order->id.'/contact', ['contact_user_id' => $inactive->id])
            ->assertSessionHasErrors('contact_user_id');
        $this->patch('/orders/'.$order->id.'/contact', ['contact_user_id' => $operations->id])
            ->assertRedirect();
        self::assertEquals($operations->id, $order->fresh()->contact_user_id);
        $this->patch('/orders/'.$order->id.'/contact', ['contact_user_id' => $logistics->id])
            ->assertRedirect();
        self::assertEquals($logistics->id, $order->fresh()->contact_user_id);
        $this->actingAs($logistics)->patch('/orders/'.$order->id.'/contact', ['contact_user_id' => $creator->id])
            ->assertForbidden();
        $this->actingAs($creator)->patch('/orders/'.$order->id.'/contact', ['contact_user_id' => $creator->id])
            ->assertRedirect();
        $order->refresh();
        self::assertEquals($creator->id, $order->owner_id);
        self::assertEquals($creator->id, $order->contact_user_id);
        $record = \App\Modules\Audit\Infrastructure\Models\AuditRecord::query()
            ->where('action', 'order.contact_changed')->where('entity_id', $order->id)->latest('id')->firstOrFail();
        self::assertEquals($creator->id, $record->actor_id);
        self::assertEquals($logistics->id, $record->before['contact_user_id']);
        self::assertEquals($creator->id, $record->after['contact_user_id']);

    }

    public function test_legacy_parent_and_replacement_use_effective_contact_without_backfill(): void
    {
        $this->withoutVite();
        $owner = $this->staff(RolePreset::KolSpecialist);
        $contact = $this->staff(RolePreset::KolManager);
        $logistics = $this->staff(RolePreset::Logistics);
        $kol = Kol::create(['display_name' => 'Legacy synthetic', 'owner_id' => $contact->id, 'status' => 'active', 'relationship_stage' => 'cooperating']);
        $base = ['source_type' => 'manual', 'order_date' => '2026-11-24', 'order_status' => 'pending_shipment', 'currency' => 'USD'];
        $root = Order::create([...$base, 'internal_order_number' => 'CONTACT-ROOT', 'erp_order_id' => 'K26112401-007', 'external_order_number' => 'CONTACT-ROOT', 'order_kind' => 'kol_fulfillment', 'kol_id' => $kol->id, 'owner_id' => $owner->id]);
        $child = Order::create([...$base, 'internal_order_number' => 'CONTACT-CHILD', 'erp_order_id' => 'K26112401-007A', 'external_order_number' => 'CONTACT-CHILD', 'order_kind' => 'fulfillment_derived', 'fulfillment_parent_id' => $root->id, 'fulfillment_role' => 'original', 'fulfillment_suffix' => 'A', 'owner_id' => $logistics->id]);
        $projection = new \App\Modules\Orders\Application\OrderContactProjection();
        $projection->prime([$root, $child]);
        self::assertEquals($contact->id, $projection->for($root)['contact_user_id']);
        self::assertEquals($contact->id, $projection->for($child)['contact_user_id']);
        self::assertNull($root->fresh()->contact_user_id);
        self::assertNull($child->fresh()->contact_user_id);
        $query = Order::query();
        $projection->applyNameFilter($query, $contact->name);
        self::assertEqualsCanonicalizing([$root->id, $child->id], $query->pluck('id')->all());
        $this->actingAs($owner)->get('/kol/orders/'.$child->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('order.contact_user_id', $contact->id)->where('canEditContact', false));
        $this->patch('/orders/'.$child->id.'/contact', ['contact_user_id' => $owner->id])->assertStatus(409);
        $this->patch('/orders/'.$root->id.'/contact', ['contact_user_id' => $logistics->id])->assertRedirect();
        self::assertEquals($logistics->id, (new \App\Modules\Orders\Application\OrderContactProjection())->for($child)['contact_user_id']);
        self::assertNull($child->fresh()->contact_user_id);
    }

    public function test_woocommerce_customer_default_and_sync_do_not_override_explicit_contact(): void
    {
        $first = $this->staff(RolePreset::Operations);
        config()->set('order_contact.operations_user_id', $first->id);
        $second = $this->staff(RolePreset::Logistics);
        $service = app(\App\Modules\Orders\Application\OrderService::class);
        $payload = fn ($modified) => new \App\Modules\Orders\Application\Inbound\NormalizedInboundOrder(
            'woocommerce', 'synthetic.test', '987654', '987654', '2026-11-24', $modified,
            'USD', 'pending_shipment', null, ['recipient_name' => 'Synthetic', 'country' => 'US'], []
        );
        $created = $service->upsertInbound($payload('2026-11-24T00:00:00Z'), null);
        $order = $created['order'];
        self::assertEquals($first->id, $order->contact_user_id);
        $order->update(['contact_user_id' => $second->id]);
        $service->upsertInbound($payload('2026-11-24T01:00:00Z'), null);
        self::assertEquals($second->id, $order->fresh()->contact_user_id);
        self::assertNull($order->fresh()->owner_id);
    }

    public function test_contact_filter_is_server_side_and_never_expands_kol_visibility(): void
    {
        $this->withoutVite();
        $staff = $this->staff(RolePreset::KolSpecialist);
        $other = $this->staff(RolePreset::KolSpecialist);
        $manager = $this->staff(RolePreset::KolManager);
        $ops = $this->staff(RolePreset::Operations);
        $base = ['source_type' => 'manual', 'order_date' => '2026-11-24', 'order_status' => 'pending_shipment', 'currency' => 'USD', 'order_kind' => 'kol_fulfillment'];
        $mine = Order::create([...$base, 'internal_order_number' => 'FILTER-OWN', 'erp_order_id' => 'FILTER-OWN', 'external_order_number' => 'FILTER-OWN', 'owner_id' => $staff->id, 'contact_user_id' => $manager->id]);
        $theirs = Order::create([...$base, 'internal_order_number' => 'FILTER-OTHER', 'erp_order_id' => 'FILTER-OTHER', 'external_order_number' => 'FILTER-OTHER', 'owner_id' => $other->id, 'contact_user_id' => $manager->id]);
        $query = '?filter_contact='.urlencode($manager->name);
        $this->actingAs($staff)->get('/kol/logistics'.$query)->assertOk()
            ->assertInertia(fn ($page) => $page->where('orders.total', 1)->where('orders.data.0.id', $mine->id)->where('orders.data.0.contact_user_id', $manager->id));
        $this->actingAs($manager)->get('/kol/logistics'.$query)->assertOk()
            ->assertInertia(fn ($page) => $page->where('orders.total', 2));
        $this->actingAs($ops)->get('/logistics/wait-stock?filter_contact=NO-SUCH-CONTACT')->assertOk()
            ->assertInertia(fn ($page) => $page->where('orders.total', 0));
        $mine->shipment()->create(['shipment_status' => 'completed', 'tracking_number' => 'SYN-TRACK', 'shipped_at' => now()]);
        $theirs->shipment()->create(['shipment_status' => 'completed', 'tracking_number' => 'SYN-OTHER', 'shipped_at' => now()]);
        $this->get('/logistics/completed'.$query)->assertOk()
            ->assertInertia(fn ($page) => $page->where('shipments.total', 2)->where('shipments.data.0.contact_user_id', $manager->id));
        $this->get('/logistics/completed?filter_contact=NO-SUCH-CONTACT')->assertOk()
            ->assertInertia(fn ($page) => $page->where('shipments.total', 0));
        $mine->update(['operational_archived_at' => now()]);
        $this->actingAs($staff)->get('/orders/archive'.$query)->assertOk()
            ->assertInertia(fn ($page) => $page->where('orders.total', 1)->where('orders.data.0.id', $mine->id)->where('orders.data.0.contact_user_id', $manager->id));
        $this->get('/orders/archive?filter_contact=NO-SUCH-CONTACT')->assertOk()
            ->assertInertia(fn ($page) => $page->where('orders.total', 0));
    }

    public function test_manual_customer_creation_defaults_to_operations_but_allows_other_active_staff(): void
    {
        $this->withoutVite();
        $ops = $this->staff(RolePreset::Operations);
        $kol = $this->staff(RolePreset::KolManager);
        $logistics = $this->staff(RolePreset::Logistics);
        $inactive = $this->staff(RolePreset::Operations, false);
        config()->set('order_contact.operations_user_id', $ops->id);
        $product = Product::create(['source_type' => 'manual', 'sku' => 'SYN-CUSTOMER-CONTACT', 'name' => 'Synthetic product', 'is_active' => true]);
        $payload = [
            'external_order_number' => 'SYN-CUSTOMER-DEFAULT', 'order_date' => '2026-11-24', 'currency' => 'USD',
            'recipient_name' => 'Synthetic Recipient', 'country' => 'US',
            'items' => [['product_id' => $product->id, 'product_summary' => 'Synthetic product', 'sku' => $product->sku, 'quantity' => 1, 'unit_price' => '0.00']],
        ];
        $this->actingAs($ops)->get('/customers/orders/create')->assertOk()
            ->assertInertia(fn ($page) => $page->where('defaultCustomerContactId', $ops->id)->has('contactOptions', 3));
        $this->post('/customers/orders', [...$payload, 'contact_user_id' => $inactive->id])->assertSessionHasErrors('contact_user_id');
        $this->post('/customers/orders', $payload)->assertRedirect();
        $default = Order::where('external_order_number', $payload['external_order_number'])->sole();
        self::assertEquals($ops->id, $default->contact_user_id);
        $this->post('/customers/orders', [...$payload, 'external_order_number' => 'SYN-CUSTOMER-MANUAL', 'contact_user_id' => $kol->id])->assertRedirect();
        $manual = Order::where('external_order_number', 'SYN-CUSTOMER-MANUAL')->sole();
        self::assertEquals($kol->id, $manual->contact_user_id);
        $this->get('/customers/orders/'.$manual->id)->assertOk()
            ->assertInertia(fn ($page) => $page->has('contactOptions', 3)->where('order.contact_user_id', $kol->id));
        $this->patch('/orders/'.$manual->id.'/contact', ['contact_user_id' => $logistics->id])->assertRedirect();
        self::assertEquals($logistics->id, $manual->fresh()->contact_user_id);
        self::assertEquals($ops->id, $manual->fresh()->owner_id);
        $this->patch('/orders/'.$manual->id.'/contact', ['contact_user_id' => $inactive->id])->assertSessionHasErrors('contact_user_id');
        self::assertEquals($logistics->id, $manual->fresh()->contact_user_id);
    }
}
