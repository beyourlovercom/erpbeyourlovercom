<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->text('domestic_reject_reason')->nullable();
            $table->timestamp('domestic_rejected_at')->nullable();
            $table->foreignId('domestic_rejected_by')->nullable()->constrained('users')->nullOnDelete();
        });

        $permission = DB::table('permissions')->where('key', 'domestic.review')->first();
        $permissionId = $permission?->id ?? DB::table('permissions')->insertGetId([
            'key' => 'domestic.review', 'label' => '国内商务审核', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $roles = DB::table('roles')->whereIn('key', [
            'role_preset_operations', 'role_preset_administrator',
        ])->pluck('id');
        foreach ($roles as $roleId) {
            DB::table('permission_role')->updateOrInsert([
                'permission_id' => $permissionId, 'role_id' => $roleId,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('domestic_rejected_by');
            $table->dropColumn(['domestic_reject_reason', 'domestic_rejected_at']);
        });
        // Retain permission grants on rollback to avoid deleting user-managed ACLs.
    }
};
