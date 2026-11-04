<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const KEY = 'inventory.overdue.manage';

    public function up(): void
    {
        DB::table('permissions')->updateOrInsert(
            ['key' => self::KEY],
            ['label' => '管理超期库存屏蔽商品', 'created_at' => now(), 'updated_at' => now()],
        );
        $permissionId = DB::table('permissions')->where('key', self::KEY)->value('id');
        foreach (['role_preset_administrator', 'role_preset_operations'] as $roleKey) {
            $roleId = DB::table('roles')->where('key', $roleKey)->value('id');
            if ($roleId !== null && $permissionId !== null) {
                DB::table('permission_role')->updateOrInsert([
                    'role_id' => $roleId, 'permission_id' => $permissionId,
                ], []);
            }
        }
    }

    public function down(): void
    {
        // Never reset historical user decisions or grant broader inventory edits.
        $permissionId = DB::table('permissions')->where('key', self::KEY)->value('id');
        if ($permissionId !== null) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permission_user')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
