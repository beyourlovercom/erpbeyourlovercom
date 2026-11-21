<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Exact KOL lookup checks these external usernames independently.
        // Keep the stored handles intact; no normalization or uniqueness rule.
        Schema::table('kol_social_accounts', function (Blueprint $table): void {
            $table->index(['handle', 'is_active', 'kol_id'], 'kol_social_handle_exact_idx');
        });

        Schema::table('kol_affiliate_accounts', function (Blueprint $table): void {
            $table->index(['wordpress_user_login', 'kol_id'], 'kol_affiliate_login_exact_idx');
        });
    }

    public function down(): void
    {
        Schema::table('kol_affiliate_accounts', function (Blueprint $table): void {
            $table->dropIndex('kol_affiliate_login_exact_idx');
        });

        Schema::table('kol_social_accounts', function (Blueprint $table): void {
            $table->dropIndex('kol_social_handle_exact_idx');
        });
    }
};
