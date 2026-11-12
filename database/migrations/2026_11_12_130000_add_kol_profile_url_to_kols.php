<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('kols', function (Blueprint $table): void {
            // Legacy KOLs remain valid; new creation and account provisioning require a URL.
            $table->string('kol_profile_url', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('kols', function (Blueprint $table): void {
            $table->dropColumn('kol_profile_url');
        });
    }
};
