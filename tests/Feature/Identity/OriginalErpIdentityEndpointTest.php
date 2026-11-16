<?php

namespace Tests\Feature\Identity;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OriginalErpIdentityEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_read_any_identity_or_permissions(): void
    {
        $this->getJson('/_erp2/identity')->assertUnauthorized();
    }

    public function test_role_mode_returns_only_signed_in_principal_and_saved_role_grants(): void
    {
        $user = User::factory()->create(['is_active' => true, 'permission_mode' => 'role']);
        $other = User::factory()->create(['is_active' => true, 'name' => 'Other Staff']);
        $role = Role::query()->create(['key' => 'role_preset_logistics', 'label' => 'Logistics']);
        $view = Permission::query()->create(['key' => 'logistics.view', 'label' => 'View logistics']);
        $operate = Permission::query()->create(['key' => 'logistics.operate', 'label' => 'Operate logistics']);
        $role->permissions()->attach([$view->id, $operate->id]);
        $user->roles()->attach($role);

        $result = $this->actingAs($user)->getJson('/_erp2/identity?user_id='.$other->id);
        $result->assertOk()
            ->assertJsonPath('source', 'ERP_ORIGINAL_LARAVEL_PRODUCTION_SESSION_V1')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('permissionMode', 'role')
            ->assertJsonPath('roleKeys', ['role_preset_logistics'])
            ->assertJsonPath('effectivePermissionKeys', ['logistics.operate', 'logistics.view']);
        $this->assertSame(['source', 'user', 'permissionMode', 'roleKeys', 'effectivePermissionKeys', 'navigation'],
            array_keys($result->json()));
        $result->assertDontSee('Other Staff');
        $result->assertDontSee('remember_token');
        $result->assertDontSee('password');
        $this->assertStringContainsString('no-store', (string) $result->headers->get('Cache-Control'));
        $this->assertSame('Cookie', $result->headers->get('Vary'));
    }

    public function test_custom_mode_uses_only_saved_user_grants_not_role_default(): void
    {
        $user = User::factory()->create(['is_active' => true, 'permission_mode' => 'custom']);
        $role = Role::query()->create(['key' => 'role_preset_operations', 'label' => 'Operations']);
        $rolePermission = Permission::query()->create(['key' => 'orders.view', 'label' => 'Orders']);
        $customPermission = Permission::query()->create(['key' => 'guides.view', 'label' => 'Guides']);
        $role->permissions()->attach($rolePermission);
        $user->roles()->attach($role);
        $user->customPermissions()->attach($customPermission);

        $this->actingAs($user)->getJson('/_erp2/identity')->assertOk()
            ->assertJsonPath('permissionMode', 'custom')
            ->assertJsonPath('roleKeys', ['role_preset_operations'])
            ->assertJsonPath('effectivePermissionKeys', ['guides.view']);
    }

    public function test_permission_revocation_is_visible_immediately_on_next_read(): void
    {
        $user = User::factory()->create(['is_active' => true, 'permission_mode' => 'role']);
        $role = Role::query()->create(['key' => 'role_preset_logistics', 'label' => 'Logistics']);
        $view = Permission::query()->create(['key' => 'logistics.view', 'label' => 'View logistics']);
        $operate = Permission::query()->create(['key' => 'logistics.operate', 'label' => 'Operate logistics']);
        $role->permissions()->attach([$view->id, $operate->id]);
        $user->roles()->attach($role);

        $this->actingAs($user)->getJson('/_erp2/identity')->assertOk()
            ->assertJsonPath('effectivePermissionKeys', ['logistics.operate', 'logistics.view']);
        $role->permissions()->sync([$view->id]);
        $this->actingAs($user->fresh())->getJson('/_erp2/identity')->assertOk()
            ->assertJsonPath('effectivePermissionKeys', ['logistics.view']);
    }

    public function test_super_admin_and_inactive_users_do_not_bypass_canonical_rules(): void
    {
        $super = User::factory()->create(['is_active' => true, 'permission_mode' => 'role']);
        $role = Role::query()->create(['key' => 'super_admin', 'label' => 'Super Admin']);
        $super->roles()->attach($role);
        $this->actingAs($super)->getJson('/_erp2/identity')->assertOk()
            ->assertJsonPath('effectivePermissionKeys', ['*']);

        $disabled = User::factory()->create(['is_active' => false, 'permission_mode' => 'role']);
        $this->actingAs($disabled)->get('/_erp2/identity')->assertRedirect('/login');
    }
}
