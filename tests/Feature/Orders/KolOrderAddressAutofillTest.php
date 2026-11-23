<?php

namespace Tests\Feature\Orders;

use App\Modules\Identity\Infrastructure\Models\Permission;
use App\Modules\Identity\Infrastructure\Models\Role;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class KolOrderAddressAutofillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function staff(): User
    {
        $actor = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'address-autofill-'.uniqid(), 'label' => 'Order KOL']);
        foreach (['orders.create', 'kol.view'] as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        }
        $actor->roles()->attach($role);
        return $actor;
    }

    private function kol(User $owner): Kol
    {
        return Kol::query()->create([
            'display_name' => 'Creator with saved address', 'owner_id' => $owner->id,
            'status' => 'active', 'relationship_stage' => 'cooperating',
            'country_code' => 'New Zealand', 'shipping_country' => 'NZ',
            'contact_email' => 'creator@example.test', 'contact_phone' => '+64000123',
            'shipping_recipient_name' => 'Saved Person',
            'shipping_province_state' => 'Auckland',
            'shipping_city' => 'Auckland',
            'shipping_address_line_1' => '100 Saved Rd',
            'shipping_address_line_2' => 'Level 2',
            'shipping_postal_code' => '1010',
        ]);
    }

    public function test_preselected_kol_and_authorized_lookup_return_saved_address_and_contact_fields(): void
    {
        $actor = $this->staff();
        $kol = $this->kol($actor);
        $this->actingAs($actor)->get('/kol/orders/create?kol_id='.$kol->id)
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('selectedKolId', $kol->id)
                ->where('selectedKolAddress.recipient_name', 'Saved Person')
                ->where('selectedKolAddress.phone', '+64000123')
                ->where('selectedKolAddress.email', 'creator@example.test')
                ->where('selectedKolAddress.country', 'NZ')
                ->where('selectedKolAddress.address_line_1', '100 Saved Rd'));
        $this->actingAs($actor)->getJson('/kol/orders/kol-address?kol_id='.$kol->id)
            ->assertOk()->assertJsonPath('address.city', 'Auckland');

        $outsider = $this->staff();
        $this->actingAs($outsider)->getJson('/kol/orders/kol-address?kol_id='.$kol->id)
            ->assertNotFound();
    }

    public function test_mexico_order_receives_encrypted_customs_id_from_kol_address_snapshot(): void
    {
        $actor = $this->staff();
        $kol = $this->kol($actor);
        $kol->update([
            'shipping_country' => 'MX',
            'shipping_customs_id' => 'SYNTHETIC-CUSTOMS-ID-098',
        ]);
        $this->actingAs($actor)->getJson('/kol/orders/kol-address?kol_id='.$kol->id)
            ->assertOk()->assertJsonPath('address.recipient_customs_id', 'SYNTHETIC-CUSTOMS-ID-098');

        $product = Product::create(['source_type' => 'manual', 'sku' => 'SYN-CUSTOMS-OK-01', 'name' => 'Synthetic Product', 'is_active' => true]);
        $this->actingAs($actor)->post('/kol/orders', [
            'external_order_number' => 'SYN-CUSTOMS-OK-ORDER', 'kol_id' => $kol->id,
            'order_date' => '2026-11-23', 'currency' => 'USD',
            'items' => [['product_id' => $product->id, 'product_summary' => 'Synthetic Product', 'quantity' => 1, 'unit_price' => '0.00']],
        ])->assertRedirect();
        $address = Order::query()->firstOrFail()->address;
        self::assertSame('MX', $address->country);
        self::assertSame('SYNTHETIC-CUSTOMS-ID-098', $address->recipient_customs_id);
        self::assertNotSame($address->recipient_customs_id, $address->getRawOriginal('recipient_customs_id'));
        $kol->update(['shipping_customs_id' => 'SYNTHETIC-CHANGED']);
        self::assertSame('SYNTHETIC-CUSTOMS-ID-098', $address->fresh()->recipient_customs_id);
    }

    public function test_missing_mexico_customs_id_blocks_order_without_writing_and_customs_id_never_crosses_countries(): void
    {
        $actor = $this->staff();
        $kol = $this->kol($actor);
        $kol->update(['shipping_country' => 'MX', 'shipping_customs_id' => null]);
        $product = Product::create(['source_type' => 'manual', 'sku' => 'SYN-CUSTOMS-FAIL-01', 'name' => 'Synthetic Product', 'is_active' => true]);
        $payload = [
            'external_order_number' => 'SYN-CUSTOMS-NO-ID', 'kol_id' => $kol->id,
            'order_date' => '2026-11-23', 'currency' => 'USD',
            'items' => [['product_id' => $product->id, 'product_summary' => 'Synthetic Product', 'quantity' => 1, 'unit_price' => '0.00']],
        ];
        $this->actingAs($actor)->post('/kol/orders', $payload)->assertSessionHasErrors('recipient_customs_id');
        $this->assertDatabaseCount('orders', 0);

        $kol->update(['shipping_customs_id' => 'SYNTHETIC-MX-ONLY']);
        $this->actingAs($actor)->post('/kol/orders', [
            ...$payload, 'country' => 'Brazil',
        ])->assertSessionHasErrors('recipient_customs_id');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_store_fills_blank_address_from_kol_and_preserves_specific_override(): void
    {
        $actor = $this->staff();
        $kol = $this->kol($actor);
        $product = Product::create(['source_type' => 'manual', 'sku' => 'SYN-KOL-AUTO-01', 'name' => 'Test Sample', 'is_active' => true]);
        $payload = [
            'external_order_number' => 'SYN-KOL-AUTO-ORDER', 'kol_id' => $kol->id,
            'order_date' => '2026-11-23', 'currency' => 'USD',
            'address_line_1' => 'Shipment-specific address',
            'items' => [['product_id' => $product->id, 'product_summary' => 'Test Sample', 'quantity' => 1, 'unit_price' => '0.00']],
        ];
        $this->actingAs($actor)->post('/kol/orders', $payload)->assertRedirect();
        $order = Order::query()->with('address')->firstOrFail();
        self::assertSame($kol->id, $order->kol_id);
        self::assertSame('Saved Person', $order->address->recipient_name);
        self::assertSame('creator@example.test', $order->address->email);
        self::assertSame('+64000123', $order->address->phone);
        self::assertSame('NZ', $order->address->country);
        self::assertSame('Auckland', $order->address->city);
        self::assertSame('Shipment-specific address', $order->address->address_line_1);
        self::assertSame('Level 2', $order->address->address_line_2);
        self::assertSame('1010', $order->address->postal_code);
    }
}
