<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
use App\Modules\Orders\Application\OrderIdentityService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class KolTwoHopNavigationV1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('services.andy_core.connection_key', 'beyourlover.com');
    }

    public function test_affiliate_admin_link_uses_affiliate_id_not_wordpress_user_id(): void
    {
        $user = $this->viewer(['kol.view']);
        $kol = $this->kol($user);
        KolAffiliateAccount::create([
            'connection_key' => 'beyourlover.com', 'affiliate_id' => 656,
            'wordpress_user_id' => 9000, 'kol_id' => $kol->id,
            'provider_status' => 'active', 'origin' => 'external_sync',
        ]);
        $this->actingAs($user)->get('/kol/library/'.$kol->id)->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('affiliateAdminUrl', 'https://www.beyourlover.com/wp-admin/admin.php?page=affiliate-wp-affiliates&affiliate_id=656&action=edit_affiliate')
                ->where('affiliateAccount.affiliate_id', 656)
                ->where('affiliateAccount.wordpress_user_id', 9000));

        config()->set('services.andy_core.connection_key', 'other-store');
        $this->actingAs($user)->get('/kol/library/'.$kol->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('affiliateAdminUrl', null));
    }

    public function test_kol_order_links_only_to_authoritative_own_profile(): void
    {
        $owner = $this->viewer(['orders.view', 'kol.view']);
        $other = $this->viewer(['orders.view', 'kol.view']);
        $kol = $this->kol($owner);
        $order = $this->order($owner, $kol->id);
        $this->actingAs($owner)->get('/kol/orders/'.$order->id)->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('kolProfile.id', $kol->id)
                ->where('kolProfile.url', '/kol/library/'.$kol->id));
        $this->actingAs($other)->get('/kol/orders/'.$order->id)->assertForbidden();
    }

    public function test_historical_order_preview_does_not_invent_matches(): void
    {
        $user = $this->viewer(['orders.view', 'kol.views.manage']);
        $this->order($user, null);
        $this->actingAs($user)->get('/kol/orders/link-preview')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Kol/OrderLinkPreview')
                ->where('preview.auto_match_enabled', false)->where('preview.unlinked', 1));
        $this->actingAs($user)->getJson('/kol/orders/link-preview')->assertOk()
            ->assertJsonPath('mode', 'preview_only')
            ->assertJsonPath('auto_match_enabled', false)
            ->assertJsonPath('total_kol_orders', 1)
            ->assertJsonPath('explicitly_linked', 0)
            ->assertJsonPath('unlinked', 1)
            ->assertJsonCount(1, 'unlinked_sample');
        $limited = $this->viewer(['orders.view']);
        $this->actingAs($limited)->getJson('/kol/orders/link-preview')->assertForbidden();
    }

    public function test_r6_profile_order_entry_preselects_only_permitted_kol(): void
    {
        $staff = $this->viewer(['orders.view', 'orders.create', 'kol.view']);
        $outsider = $this->viewer(['orders.view', 'orders.create', 'kol.view']);
        $kol = $this->kol($staff);
        $this->bindAffiliate($kol);

        $this->actingAs($staff)->get('/kol/orders/create?kol_id='.$kol->id)->assertOk()
            ->assertInertia(fn ($page) => $page->component('Orders/Create')
                ->where('selectedKolId', $kol->id));

        $this->actingAs($outsider)->get('/kol/orders/create?kol_id='.$kol->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('selectedKolId', null));

        $this->actingAs($staff)->get('/customers/orders/create?kol_id='.$kol->id)->assertForbidden();

        $kol->forceFill(['archived_at' => now()])->save();
        $this->actingAs($staff)->get('/kol/orders/create?kol_id='.$kol->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('selectedKolId', null));
    }

    public function test_kol_staff_must_select_an_authorized_kol_on_new_manual_order(): void
    {
        $staff = $this->viewer(['orders.view', 'orders.create', 'kol.view']);
        $outsider = $this->viewer(['orders.view', 'orders.create', 'kol.view']);
        $kol = $this->kol($staff);
        $this->bindAffiliate($kol);
        $product = Product::create(['source_type' => 'manual', 'sku' => 'TW-HOP-SKU', 'name' => 'Two Hop Product', 'is_active' => true]);
        $payload = [
            'external_order_number' => 'TW-HOP-ORDER', 'order_date' => '2026-10-29',
            'currency' => 'USD',
            'recipient_name' => 'Test', 'phone' => '123', 'country' => 'US',
            'province_state' => 'CA', 'city' => 'Los Angeles', 'address_line_1' => 'Address',
            'items' => [['product_id' => $product->id, 'product_summary' => 'Two Hop Product', 'quantity' => 1, 'unit_price' => '0.00']],
        ];
        $this->actingAs($staff)->post('/kol/orders', $payload)->assertSessionHasErrors('kol_id');
        $this->assertDatabaseCount('orders', 0);
        $this->actingAs($outsider)->post('/kol/orders', [...$payload, 'kol_id' => $kol->id])->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
        $this->actingAs($staff)->post('/kol/orders', [...$payload, 'kol_id' => $kol->id])->assertRedirect();
        $this->assertDatabaseHas('orders', ['order_kind' => 'kol_fulfillment', 'kol_id' => $kol->id]);
    }

    public function test_manual_kol_erp_identity_uses_database_order_id_without_numeric_offset(): void
    {
        $staff = $this->viewer(['orders.view', 'orders.create', 'kol.view']);
        $kol = $this->kol($staff);
        $this->bindAffiliate($kol);
        app(OrderIdentityService::class)->updatePrefix('007', $staff);

        $product = Product::create(['source_type' => 'manual', 'sku' => 'APPROVED-ID-001', 'name' => 'Approved ID', 'is_active' => true]);
        $payload = [
            'external_order_number' => 'KOL-APPROVED-ID', 'order_date' => '2026-10-30',
            'kol_id' => $kol->id, 'currency' => 'USD',
            'recipient_name' => 'Synthetic', 'phone' => '123', 'country' => 'US',
            'province_state' => 'CA', 'city' => 'Los Angeles', 'address_line_1' => 'Synthetic',
            'items' => [['product_id' => $product->id, 'product_summary' => 'Approved ID', 'quantity' => 1, 'unit_price' => '0.00']],
        ];

        $this->actingAs($staff)->post('/kol/orders', $payload)->assertRedirect();
        $first = Order::query()->firstOrFail();
        $this->assertSame('007-1030-k'.$first->id, $first->erp_order_id);
        $this->assertSame('KOL-APPROVED-ID', $first->external_order_number);
        $this->assertSame($kol->id, (int) $first->kol_id);

        $this->actingAs($staff)->post('/kol/orders', [...$payload, 'external_order_number' => 'KOL-APPROVED-ID-2'])->assertRedirect();
        $second = Order::query()->latest('id')->firstOrFail();
        $this->assertSame('007-1030-k'.$second->id, $second->erp_order_id);
        $this->assertNotSame($first->erp_order_id, $second->erp_order_id);
    }

    public function test_r61_unbound_or_inactive_affiliate_blocks_all_manual_kol_orders(): void
    {
        $staff = $this->viewer(['orders.view', 'orders.create', 'kol.view']);
        $legacyRole = $this->viewer(['orders.view', 'orders.create']);
        $kol = $this->kol($staff);
        $product = Product::create(['source_type' => 'manual', 'sku' => 'R61-ITEM', 'name' => 'R61', 'is_active' => true]);
        $payload = [
            'external_order_number' => 'R61-MANUAL-ORDER', 'order_date' => '2026-10-30',
            'currency' => 'USD', 'kol_id' => $kol->id,
            'recipient_name' => 'Test', 'phone' => '123', 'country' => 'US',
            'province_state' => 'CA', 'city' => 'Los Angeles', 'address_line_1' => 'Sample',
            'items' => [['product_id' => $product->id, 'product_summary' => 'R61', 'quantity' => 1, 'unit_price' => '0.00']],
        ];
        $this->actingAs($staff)->get('/kol/library/'.$kol->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('affiliateAdminUrl', null));
        $this->actingAs($staff)->get('/kol/orders/create?kol_id='.$kol->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('selectedKolId', null)
                ->where('requestedKolUnbound', true)
                ->where('kolOptions.0.hasAffiliate', false));
        $this->actingAs($staff)->post('/kol/orders', $payload)->assertSessionHasErrors('kol_id');
        $this->actingAs($legacyRole)->post('/kol/orders', $payload)->assertForbidden();
        $this->assertDatabaseCount('orders', 0);

        $this->bindAffiliate($kol, 'inactive');
        $this->actingAs($staff)->post('/kol/orders', $payload)->assertSessionHasErrors('kol_id');
        $this->actingAs($staff)->get('/kol/library/'.$kol->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('affiliateAdminUrl', null));
        $this->assertDatabaseCount('orders', 0);

        KolAffiliateAccount::where('kol_id', $kol->id)->update(['provider_status' => 'active']);
        $this->actingAs($staff)->get('/kol/orders/create?kol_id='.$kol->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('selectedKolId', $kol->id)
                ->where('requestedKolUnbound', false)
                ->where('kolOptions.0.hasAffiliate', true));
        $this->actingAs($staff)->post('/kol/orders', $payload)->assertRedirect();
        $this->assertDatabaseHas('orders', ['order_kind' => 'kol_fulfillment', 'kol_id' => $kol->id]);
    }

    private function bindAffiliate(Kol $kol, string $status = 'active'): void
    {
        KolAffiliateAccount::create([
            'connection_key' => 'beyourlover.com', 'affiliate_id' => 20000 + $kol->id,
            'wordpress_user_id' => 30000 + $kol->id, 'kol_id' => $kol->id,
            'provider_status' => $status, 'origin' => 'external_sync',
        ]);
    }

    private function viewer(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'two-hop-'.uniqid(), 'label' => 'Two Hop']);
        foreach ($permissions as $permission) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $permission], ['label' => $permission]));
        }
        $user->roles()->attach($role);

        return $user;
    }

    private function kol(User $owner): Kol
    {
        return Kol::create([
            'display_name' => 'Two Hop KOL', 'status' => 'active',
            'relationship_stage' => 'cooperating', 'owner_id' => $owner->id,
        ]);
    }

    private function order(User $owner, ?int $kolId): Order
    {
        return Order::create([
            'internal_order_number' => 'TWH-'.uniqid(),
            'erp_order_id' => '007-test-k'.uniqid(),
            'source_type' => 'kol', 'order_kind' => 'kol_fulfillment', 'kol_id' => $kolId,
            'external_order_number' => 'TW-'.$owner->id.'-'.uniqid(),
            'order_date' => '2026-10-29', 'owner_id' => $owner->id,
            'order_status' => 'pending_shipment', 'currency' => 'USD',
        ]);
    }
}
