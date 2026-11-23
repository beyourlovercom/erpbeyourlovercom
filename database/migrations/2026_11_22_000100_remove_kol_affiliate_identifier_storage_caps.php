<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kol_affiliate_accounts', function (Blueprint $table): void {
            $table->text('affiliate_url_slug')->nullable()->change();
            $table->text('display_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('kol_affiliate_accounts')->whereRaw('CHAR_LENGTH(affiliate_url_slug) > 60 OR CHAR_LENGTH(display_name) > 255')->exists()) {
            throw new RuntimeException('Refusing to truncate human-entered Affiliate identifiers on rollback.');
        }

        Schema::table('kol_affiliate_accounts', function (Blueprint $table): void {
            $table->string('affiliate_url_slug', 60)->nullable()->change();
            $table->string('display_name', 255)->nullable()->change();
        });
    }
};
