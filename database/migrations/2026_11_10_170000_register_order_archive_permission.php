<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const KEY = 'orders.archive';
    private const ROLE_KEYS = ['role_preset_administrator', 'role_preset_operations', 'role_preset_logistics', 'role_preset_domestic_business'];

    public function up(): void
    {
        DB::table('permissions')->updateOrInsert(
            ['key' => self::KEY],
            ['label' => '归档订单', 'created_at' => now(), 'updated_at' => now()],
        );
        $permissionId = DB::table('permissions')->where('key', self::KEY)->value('id');
        foreach (DB::table('roles')->whereIn('key', self::ROLE_KEYS)->pluck('id') as $roleId) {
            DB::table('permission_role')->updateOrInsert([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        // Retain the ACL on rollback: permissions may have been edited by administrators.
    }
};
