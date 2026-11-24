<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roles = [
            'role_preset_logistics', 'role_preset_domestic_business',
            'role_preset_operations',
            'role_preset_administrator',
        ];
        $roleIds = DB::table('roles')->whereIn('key', $roles)->pluck('id');
        if ($roleIds->isEmpty()) { return; }
        foreach (['logistics.view', 'domestic.review'] as $key) {
            $permissionId = DB::table('permissions')->where('key', $key)->value('id');
            if (!$permissionId) {
                $permissionId = DB::table('permissions')->insertGetId(['key' => $key, 'label' => $key, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ($roleIds as $roleId) {
                DB::table('permission_role')->updateOrInsert([
                    'role_id' => $roleId, 'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Grants may subsequently be managed by administrators.
        // Never revoke pre-existing or user-managed permissions automatically.
    }
};
