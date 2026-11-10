<?php

use App\Modules\Identity\Application\RolePreset;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Administrator role-mode accounts receive every registered ERP operation.
     * Preserve any existing administrator permissions; do not change other
     * role grants, custom user ACLs, or the separate live ShipOut kill-switches.
     */
    public function up(): void
    {
        $roleId = DB::table('roles')->where('key', RolePreset::Administrator->roleKey())->value('id');
        if (! $roleId) {
            return;
        }

        $keys = RolePreset::Administrator->permissions();
        $now = now();
        foreach ($keys as $key) {
            DB::table('permissions')->insertOrIgnore([
                'key' => $key,
                'label' => $key,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permissionIds = DB::table('permissions')->whereIn('key', $keys)->pluck('id');
        foreach ($permissionIds as $permissionId) {
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => (int) $roleId,
                'permission_id' => (int) $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        // Non-destructive rollback: removing granted admin rights on rollback
        // could lock out the last permissions manager. Explicit authorization
        // changes must be audited separately instead.
    }
};
