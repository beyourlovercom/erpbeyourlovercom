<?php

namespace Tests\Feature\Orders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderAdjacentNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_navigation_uses_date_then_id_and_ignores_archived_and_kol_orders(): void
    {
        $user = $this->userWith(['orders.view', 'customers.view']);
        $current = $this->order($user, 'customer', '2026-11-02', 'current');
        $older = $this->order($user, 'customer', '2026-11-01', 'older');
        $sameDateNewer = $this->order($user, 'customer', '2026-11-02', 'same-newer');
        $archived = $this->order($user, 'customer', '2026-11-03', 'archived');
        $archived->forceFill(['archived_at' => now()])->save();
        $this->order($user, 'kol_fulfillment', '2026-11-03', 'kol');

        $this->actingAs($user)->get('/customers/orders/'.$current->id)->assertOk()->assertInertia(
            fn ($page) => $page
                ->where('orderNavigation.previous_url', '/customers/orders/'.$sameDateNewer->id)
                ->where('orderNavigation.next_url', '/customers/orders/'.$older->id)
        );
        $this->actingAs($user)->get('/customers/orders/'.$sameDateNewer->id)->assertOk()->assertInertia(
            fn ($page) => $page->where('orderNavigation.previous_url', null)
        );
        $this->actingAs($user)->get('/customers/orders/'.$older->id)->assertOk()->assertInertia(
            fn ($page) => $page->where('orderNavigation.next_url', null)
        );
    }

    public function test_kol_specialist_navigation_never_exposes_another_owners_order(): void
    {
        $specialist = $this->userWith(['orders.view', 'kol.view', 'kol.operate']);
        $other = $this->userWith(['orders.view', 'kol.view', 'kol.operate']);
        $ownOld = $this->order($specialist, 'kol_fulfillment', '2026-11-01', 'own-old');
        $this->order($other, 'kol_fulfillment', '2026-11-02', 'foreign');
        $ownNew = $this->order($specialist, 'kol_fulfillment', '2026-11-03', 'own-new');

        $this->actingAs($specialist)->get('/kol/orders/'.$ownNew->id)->assertOk()->assertInertia(
            fn ($page) => $page
                ->where('orderNavigation.previous_url', null)
                ->where('orderNavigation.next_url', '/kol/orders/'.$ownOld->id)
        );
        $this->actingAs($specialist)->get('/kol/orders/'.$ownOld->id)->assertOk()->assertInertia(
            fn ($page) => $page
                ->where('orderNavigation.previous_url', '/kol/orders/'.$ownNew->id)
                ->where('orderNavigation.next_url', null)
        );
    }

    public function test_kol_manager_navigation_includes_all_permitted_kol_orders(): void
    {
        $manager = $this->userWith(['orders.view', 'kol.view', 'kol.views.manage']);
        $other = $this->userWith(['orders.view', 'kol.view']);
        $older = $this->order($manager, 'kol_fulfillment', '2026-10-01', 'older');
        $newer = $this->order($other, 'kol_fulfillment', '2026-10-02', 'newer');

        $this->actingAs($manager)->get('/kol/orders/'.$newer->id)->assertOk()->assertInertia(
            fn ($page) => $page->where('orderNavigation.next_url', '/kol/orders/'.$older->id)
        );
    }

    private function userWith(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $role = Role::create(['key' => 'adjacent_order_'.uniqid(), 'label' => 'Adjacent Order Test']);
        foreach ($keys as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['label' => $key]));
        }
        $user->roles()->attach($role);
        return $user;
    }

    private function order(User $owner, string $kind, string $date, string $suffix): Order
    {
        return Order::create([
            'internal_order_number' => 'NAV-'.strtoupper($suffix),
            'erp_order_id' => '007-nav-'.strtoupper($suffix),
            'source_type' => 'manual',
            'order_kind' => $kind,
            'external_order_number' => 'NAV-UAT-'.$suffix,
            'order_date' => $date,
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
            'owner_id' => $owner->id,
        ]);
    }
}
