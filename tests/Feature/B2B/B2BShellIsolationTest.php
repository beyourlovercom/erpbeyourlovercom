<?php

namespace Tests\Feature\B2B;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Shell\Application\ErpShellNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class B2BShellIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $keys, string $roleKey): User
    {
        $role = Role::create(['key' => $roleKey, 'label' => $roleKey]);
        foreach ($keys as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);

        return $user;
    }

    public function test_b2b_only_user_sees_b2b_and_cannot_read_consumer_customer_workspace(): void
    {
        $user = $this->makeUser(['b2b.view'], 'b2b-only-test');
        $this->actingAs($user)->get('/b2b')->assertOk();
        $this->actingAs($user)->get('/customers')->assertForbidden();
        $this->actingAs($user)->get('/customers/library')->assertForbidden();

        $menu = app(ErpShellNavigation::class)->for($user);
        $this->assertNotNull(collect($menu)->firstWhere('key', 'b2b'));
        $this->assertNull(collect($menu)->firstWhere('key', 'customers'));
    }

    public function test_b2c_only_user_cannot_see_b2b_nav_or_direct_route(): void
    {
        $user = $this->makeUser(['customers.view'], 'b2c-only-test');
        $this->actingAs($user)->get('/customers')->assertOk();
        $this->actingAs($user)->get('/b2b')->assertForbidden();

        $menu = app(ErpShellNavigation::class)->for($user);
        $this->assertNull(collect($menu)->firstWhere('key', 'b2b'));
        $this->assertNotNull(collect($menu)->firstWhere('key', 'customers'));
    }

    public function test_no_grant_implied_by_consumer_operate_or_b2b_operate(): void
    {
        $consumer = $this->makeUser(['customers.view', 'customers.operate'], 'consumer-write-test');
        $b2b = $this->makeUser(['b2b.operate'], 'b2b-write-only-test');

        $this->actingAs($consumer)->get('/b2b')->assertForbidden();
        $this->actingAs($b2b)->get('/b2b')->assertForbidden();
    }

    public function test_guest_redirects_to_login(): void
    {
        $this->get('/b2b')->assertRedirect('/login');
    }
}
