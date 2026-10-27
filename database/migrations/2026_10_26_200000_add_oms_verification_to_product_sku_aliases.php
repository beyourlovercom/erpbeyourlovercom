<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_sku_aliases', function (Blueprint $table): void {
            // Existing aliases continue to identify their ERP product, but none
            // is silently approved as an OMS dispatch code by this migration.
            $table->boolean('is_active')->default(true);
            $table->timestamp('oms_verified_at')->nullable();
            $table->foreignId('oms_verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('oms_identity_evidence')->nullable();
            $table->index(['product_id', 'is_active', 'oms_verified_at'], 'sku_alias_oms_candidates_idx');
        });
    }

    public function down(): void
    {
        Schema::table('product_sku_aliases', function (Blueprint $table): void {
            $table->dropIndex('sku_alias_oms_candidates_idx');
            $table->dropForeign(['oms_verified_by_user_id']);
            $table->dropForeign(['updated_by_user_id']);
            $table->dropColumn(['is_active', 'oms_verified_at', 'oms_verified_by_user_id', 'oms_identity_evidence', 'updated_by_user_id']);
        });
    }
};
