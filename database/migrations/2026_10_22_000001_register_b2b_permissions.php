<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = [
        'b2b.view' => '查看 B2B 业务',
        'b2b.operate' => '操作 B2B 客户和询盘',
        'b2b.assign' => '分配 B2B 询盘负责人',
        'b2b.view_all' => '查看全部 B2B 询盘',
        'b2b.export' => '导出 B2B 业务资料',
        'b2b.quotes_manage' => '管理 B2B 报价',
        'b2b.channels_manage' => '管理 B2B 接入渠道',
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::PERMISSIONS as $key => $label) {
            DB::table('permissions')->updateOrInsert(
                ['key' => $key],
                ['label' => $label, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        // Existing consumer roles get no implicit B2B rights.
        $administratorId = DB::table('roles')->where('key', 'role_preset_administrator')->value('id');
        if ($administratorId === null) {
            return;
        }

        foreach (DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->pluck('id') as $permissionId) {
            DB::table('permission_role')->updateOrInsert(
                ['role_id' => $administratorId, 'permission_id' => $permissionId], [],
            );
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permission_user')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
