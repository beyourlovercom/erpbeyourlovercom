<?php

namespace Tests\Feature\Orders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WooCommerceAdminOrderLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('services.woocommerce.connection_key', 'beyourlover.com');
        config()->set('services.woocommerce.url', 'https://www.beyourlover.com');
        config()->set('services.woocommerce.admin_order_editor_mode', 'hpos');
    }

    public function test_woo_reference_is_used_instead_of_erp_id_or_display_number(): void
    {
        $order = $this->order('woocommerce');
        $order->externalReferences()->create([
            'provider' => 'woocommerce', 'connection_key' => 'beyourlover.com', 'reference' => '28518',
        ]);
        $this->actingAs($this->viewer())->get('/customers/orders/'.$order->id)->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('wooAdminEditUrl', 'https://beyourlover.com/wp-admin/admin.php?page=wc-orders&action=edit&id=28518'));
    }

    public function test_legacy_mode_uses_legacy_editor_url(): void
    {
        config()->set('services.woocommerce.admin_order_editor_mode', 'legacy');
        $order = $this->order('woocommerce');
        $order->externalReferences()->create([
            'provider' => 'woocommerce', 'connection_key' => 'beyourlover.com', 'reference' => '33333',
        ]);
        $this->actingAs($this->viewer())->get('/customers/orders/'.$order->id)->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('wooAdminEditUrl', 'https://beyourlover.com/wp-admin/post.php?post=33333&action=edit'));
    }

    public function test_missing_wrong_shop_ambiguous_or_bad_references_never_generate_a_link(): void
    {
        $cases = [
            [],
            [['provider' => 'historical_logistics', 'connection_key' => 'legacy', 'reference' => '28518']],
            [['provider' => 'woocommerce', 'connection_key' => 'some-other-store', 'reference' => '28518']],
            [['provider' => 'woocommerce', 'connection_key' => 'beyourlover.com', 'reference' => 'C28518']],
            [['provider' => 'woocommerce', 'connection_key' => 'beyourlover.com', 'reference' => '0']],
            [
                ['provider' => 'woocommerce', 'connection_key' => 'beyourlover.com', 'reference' => '28518'],
                ['provider' => 'woocommerce', 'connection_key' => 'some-other-store', 'reference' => '99999'],
            ],
        ];
        $viewer = $this->viewer();
        foreach ($cases as $i => $references) {
            $order = $this->order('woocommerce', 'CASE-'.$i);
            foreach ($references as $reference) {
                $order->externalReferences()->create($reference);
            }
            $this->actingAs($viewer)->get('/customers/orders/'.$order->id)->assertOk()
                ->assertInertia(fn ($page) => $page->where('wooAdminEditUrl', null));
        }
    }

    public function test_manual_kol_wrong_site_archived_and_unknown_mode_fail_closed(): void
    {
        $viewer = $this->viewer();
        $manual = $this->order('manual');
        $manual->externalReferences()->create(['provider' => 'woocommerce', 'connection_key' => 'beyourlover.com', 'reference' => '99991']);
        $this->actingAs($viewer)->get('/customers/orders/'.$manual->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('wooAdminEditUrl', null));

        $woo = $this->order('woocommerce', 'REAL');
        $woo->externalReferences()->create(['provider' => 'woocommerce', 'connection_key' => 'beyourlover.com', 'reference' => '28518']);
        config()->set('services.woocommerce.url', 'https://dev.beyourlover.com');
        $this->actingAs($viewer)->get('/customers/orders/'.$woo->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('wooAdminEditUrl', null));

        config()->set('services.woocommerce.url', 'https://www.beyourlover.com');
        config()->set('services.woocommerce.admin_order_editor_mode', 'disabled');
        $this->actingAs($viewer)->get('/customers/orders/'.$woo->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('wooAdminEditUrl', null));

        config()->set('services.woocommerce.admin_order_editor_mode', 'hpos');
        $woo->archived_at = now();
        $woo->save();
        $this->actingAs($this->admin())->get('/customers/orders/'.$woo->id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('wooAdminEditUrl', null));
    }

    private function viewer(): User
    {
        return $this->userWith(['orders.view']);
    }

    private function admin(): User
    {
        return $this->userWith(['orders.view', 'system.permissions.manage']);
    }

    private function userWith(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'woo_link_'.uniqid(), 'label' => 'Woo Link UAT']);
        foreach ($keys as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        }
        $user->roles()->attach($role);

        return $user;
    }

    private function order(string $source, string $suffix = 'BASE'): Order
    {
        return Order::create([
            'internal_order_number' => 'LINK-'.$suffix.'-'.uniqid(),
            'erp_order_id' => '007-0918-c28518-'.uniqid(),
            'source_type' => $source,
            'order_kind' => 'customer',
            'external_order_number' => '28518',
            'order_date' => '2025-09-18',
            'order_status' => 'shipped',
            'currency' => 'CAD',
        ]);
    }
}
