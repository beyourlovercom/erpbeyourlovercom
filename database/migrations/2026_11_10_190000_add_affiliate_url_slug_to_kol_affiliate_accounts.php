<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('kol_affiliate_accounts', function (Blueprint $table): void {
            $table->string('affiliate_url_slug', 60)->nullable()->after('wordpress_user_login');
            $table->string('affiliate_referral_url', 512)->nullable()->after('affiliate_url_slug');
        });
    }

    public function down(): void
    {
        Schema::table('kol_affiliate_accounts', function (Blueprint $table): void {
            $table->dropColumn(['affiliate_url_slug', 'affiliate_referral_url']);
        });
    }
};
