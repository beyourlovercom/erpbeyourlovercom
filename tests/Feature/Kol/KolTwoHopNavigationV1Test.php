<?php

namespace Tests\Feature\Kol;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolAffiliateAccount;
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

    public function test_kol_staff_must_select_an_authorized_kol_on_new_manual_order(): void
    {
        $staff = $this->viewer(['orders.view', 'orders.create', 'kol.view']);
        $outsider = $this->viewer(['orders.view', 'orders.create', 'kol.view']);
        $kol = $this->kol($staff);
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
