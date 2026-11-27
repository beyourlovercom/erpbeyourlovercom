<?php

namespace Tests\Feature\ERP2;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CanonicalIdentityContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_request_cannot_read_another_users_permissions(): void
    {
        $this->getJson('/_erp2/identity')->assertUnauthorized();
    }

    public function test_authenticated_user_gets_own_real_id_role_permissions_and_authorized_navigation(): void
    {
        $role = Role::create(['key' => 'role_preset_logistics', 'label' => '物流']);
        $view = Permission::firstOrCreate(['key' => 'logistics.view'], ['label' => '物流查看']);
        $operate = Permission::firstOrCreate(['key' => 'logistics.operate'], ['label' => '物流操作']);
        $role->permissions()->attach([$view->id, $operate->id]);

        $user = User::factory()->create(['is_active' => true, 'permission_mode' => 'role']);
        $user->roles()->attach($role);

        $response = $this->actingAs($user)->getJson('/_erp2/identity');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertOk()
            ->assertJsonPath('source', 'ERP_CANONICAL_LARAVEL_DEV_SESSION_V1')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('permissionMode', 'role')
            ->assertJsonPath('roleKeys.0', 'role_preset_logistics')
            ->assertJsonCount(2, 'effectivePermissionKeys')
            ->assertJsonPath('effectivePermissionKeys.0', 'logistics.operate')
            ->assertJsonPath('effectivePermissionKeys.1', 'logistics.view')
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.remember_token')
            ->assertJsonMissingPath('user.api_token');

        $this->actingAs($user)->getJson('/_erp2/identity')
            ->assertJsonFragment(['key' => 'logistics']);
    }

    public function test_custom_permissions_override_role_and_are_not_taken_from_query_parameters(): void
    {
        $role = Role::create(['key' => 'role_preset_kol_manager', 'label' => '网红经理']);
        $manage = Permission::firstOrCreate(['key' => 'kol.payouts.approve'], ['label' => '审批佣金']);
        $view = Permission::firstOrCreate(['key' => 'kol.view'], ['label' => '查看网红']);
        $role->permissions()->attach($manage);

        $user = User::factory()->create(['is_active' => true, 'permission_mode' => 'custom']);
        $user->roles()->attach($role);
        $user->customPermissions()->attach($view);

        $other = User::factory()->create(['is_active' => true, 'permission_mode' => 'role']);
        $other->roles()->attach($role);

        $response = $this->actingAs($user)->getJson('/_erp2/identity?user_id='.$other->id.'&role=super_admin');
        $response->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('permissionMode', 'custom')
            ->assertJsonPath('effectivePermissionKeys.0', 'kol.view')
            ->assertJsonCount(1, 'effectivePermissionKeys')
            ->assertJsonMissing(['kol.payouts.approve', '*']);
    }

    public function test_permission_revocation_is_reflected_by_next_authenticated_request(): void
    {
        $role = Role::create(['key' => 'role_preset_operations', 'label' => '运营']);
        $permission = Permission::firstOrCreate(['key' => 'orders.view'], ['label' => '查看订单']);
        $role->permissions()->attach($permission);

        $user = User::factory()->create(['is_active' => true, 'permission_mode' => 'role']);
        $user->roles()->attach($role);
        $this->actingAs($user)->getJson('/_erp2/identity')
            ->assertJsonPath('effectivePermissionKeys.0', 'orders.view');

        $role->permissions()->detach($permission);
        $this->actingAs(User::query()->findOrFail($user->id))->getJson('/_erp2/identity')
            ->assertJsonCount(0, 'effectivePermissionKeys');
    }
}
