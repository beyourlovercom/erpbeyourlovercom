<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppShellNavigationTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions, string $roleKey = 'operator'): User
    {
        $role = Role::create(['key' => $roleKey, 'label' => $roleKey]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $role->permissions()->attach($permission);
        }
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach($role);
        return $user;
    }

    public function test_guest_shell_access_redirects_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/sync')->assertRedirect('/login');
    }

    public function test_dashboard_renders_formal_shell_with_safe_shared_props(): void
    {
        $user = $this->userWith(['dashboard.view', 'sync.view']);
        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Index')
            ->where('auth.user.id', $user->id)
            ->where('auth.user.name', $user->name)
            ->where('auth.user.email', $user->email)
            ->missing('auth.user.password')
            ->missing('auth.user.remember_token')
            ->has('shellNavigation', 2)
            ->where('shellNavigation.0.key', 'dashboard')
            ->where('shellNavigation.1.key', 'sync'));
    }

    public function test_navigation_comes_from_formal_source_and_filters_unauthorized_modules(): void
    {
        $user = $this->userWith(['dashboard.view', 'logistics.view']);
        $response = $this->actingAs($user)->get('/dashboard');
        $response->assertInertia(fn (Assert $page) => $page
            ->where('shellNavigation.0.label', 'Dashboard')
            ->where('shellNavigation.1.label', '物流')
            ->missing('shellNavigation.2'));
        $this->actingAs($user)->get('/sync')->assertForbidden();
        $this->actingAs($user)->get('/settings')->assertForbidden();
    }

    public function test_sync_permission_controls_route_and_navigation_item(): void
    {
        $user = $this->userWith(['sync.view']);
        $this->actingAs($user)->get('/sync')->assertInertia(fn (Assert $page) => $page
            ->component('Shell/Placeholder')
            ->where('module.key', 'sync')
            ->where('shellNavigation.0.path', '/sync'));
    }

    public function test_only_exact_legacy_root_path_collisions_fall_back_for_prototype_users(): void
    {
        $prototypeUser = $this->userWith(['prototype.view'], 'prototype-operator');
        foreach (['/inventory', '/procurement', '/products', '/guides'] as $path) {
            $this->actingAs($prototypeUser)->get($path)->assertOk()->assertViewIs('erp.shell');
        }
        foreach (['/logistics', '/customers', '/kol', '/accounting', '/settings', '/sync'] as $path) {
            $this->actingAs($prototypeUser)->get($path)->assertForbidden();
        }
    }

    public function test_formal_permissions_always_render_the_corresponding_vue_placeholder(): void
    {
        $permissionsByPath = [
            '/logistics' => 'logistics.view', '/sync' => 'sync.view', '/customers' => 'customers.view',
            '/kol' => 'kol.view', '/inventory' => 'inventory.view', '/procurement' => 'procurement.view',
            '/products' => 'products.view', '/guides' => 'guides.view', '/accounting' => 'accounting.view',
            '/settings' => 'system.permissions.manage',
        ];
        $user = $this->userWith(array_values($permissionsByPath), 'formal-operator');
        foreach ($permissionsByPath as $path => $permission) {
            $this->actingAs($user)->get($path)->assertInertia(fn (Assert $page) => $page
                ->component('Shell/Placeholder')->where('module.key', trim($path, '/')));
        }
    }

    public function test_users_without_formal_or_prototype_permission_receive_forbidden_for_all_modules(): void
    {
        $user = $this->userWith([], 'restricted-operator');
        foreach (['/logistics', '/sync', '/customers', '/kol', '/inventory', '/procurement', '/products', '/guides', '/accounting', '/settings'] as $path) {
            $this->actingAs($user)->get($path)->assertForbidden();
        }
    }

    public function test_super_admin_can_access_all_formal_modules(): void
    {
        $user = $this->userWith([], 'super_admin');
        $this->actingAs($user)->get('/settings')->assertOk();
        $this->actingAs($user)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->has('shellNavigation', 11));
    }

    public function test_root_contract_and_legacy_prototype_access_remain_unchanged(): void
    {
        $user = $this->userWith(['dashboard.view', 'prototype.view']);
        $this->actingAs($user)->get('/')->assertRedirectToRoute('dashboard');
        $this->actingAs($user)->get('/logistics/pending')->assertOk();
        $this->actingAs($user)->get('/kol/orders')->assertOk();
    }

    public function test_no_public_registration_route_is_introduced(): void
    {
        $this->assertFalse(app('router')->has('register'));
        $this->post('/register')->assertNotFound();
    }
}
