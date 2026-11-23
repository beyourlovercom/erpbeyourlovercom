<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kols', function (Blueprint $table): void {
            $table->string('country_code', 128)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('kols')->whereRaw('LENGTH(country_code) > 8')->exists()) {
            throw new RuntimeException('Cannot shrink custom KOL countries without truncating operator-entered data.');
        }

        Schema::table('kols', function (Blueprint $table): void {
            $table->string('country_code', 8)->nullable()->change();
        });
    }
};
