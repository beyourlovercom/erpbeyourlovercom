<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('xlwms_dispatch_attempts', function (Blueprint $table): void {
            $table->timestamp('last_checked_at')->nullable();
            $table->string('duplicate_match_field', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('xlwms_dispatch_attempts', function (Blueprint $table): void {
            $table->dropColumn(['last_checked_at','duplicate_match_field']);
        });
    }
};
