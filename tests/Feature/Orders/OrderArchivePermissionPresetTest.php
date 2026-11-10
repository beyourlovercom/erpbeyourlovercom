<?php

namespace Tests\Feature\Orders;

use App\Modules\Identity\Application\PermissionCatalog;
use App\Modules\Identity\Application\RolePreset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class OrderArchivePermissionPresetTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_approved_role_presets_gain_order_archive_permission(): void
    {
        $approved = [RolePreset::Administrator, RolePreset::Operations, RolePreset::Logistics, RolePreset::DomesticBusiness];
        $this->assertContains('orders.archive', PermissionCatalog::allKeys());
        foreach (RolePreset::cases() as $preset) {
            $this->assertSame(in_array($preset, $approved, true), in_array('orders.archive', $preset->permissions(), true), $preset->value);
        }
        $this->assertNotContains('orders.edit', RolePreset::Logistics->permissions());
    }

    public function test_archive_permission_migration_grants_existing_roles_without_broadening_edit(): void
    {
        foreach ([RolePreset::Administrator, RolePreset::Operations, RolePreset::Logistics, RolePreset::DomesticBusiness, RolePreset::Finance] as $preset) {
            DB::table('roles')->insert(['key' => $preset->roleKey(), 'label' => $preset->label(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $migration = require database_path('migrations/2026_11_10_170000_register_order_archive_permission.php');
        $migration->up();
        $migration->up();
        $permissionId = DB::table('permissions')->where('key', 'orders.archive')->value('id');
        $this->assertNotNull($permissionId);
        $granted = DB::table('permission_role')->where('permission_id', $permissionId)->join('roles', 'roles.id', '=', 'permission_role.role_id')->pluck('roles.key')->sort()->values()->all();
        $this->assertSame(['role_preset_administrator', 'role_preset_domestic_business', 'role_preset_logistics', 'role_preset_operations'], $granted);
        $this->assertFalse(DB::table('permission_role')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')->where('permissions.key', 'orders.edit')->exists());
    }
}
