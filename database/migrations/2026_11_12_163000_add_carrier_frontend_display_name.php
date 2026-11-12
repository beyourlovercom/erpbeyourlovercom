<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logistics_carriers', function (Blueprint $table): void {
            $table->string('display_name', 128)->nullable()->after('name_zh');
        });

        // Presentation overrides only. Do not modify provider keys or bilingual master data.
        DB::table('logistics_carriers')->where('name', '4PX')->update(['display_name' => '速达']);
        DB::table('logistics_carriers')->where('name', 'Weship')->update(['display_name' => '纬狮']);
    }

    public function down(): void
    {
        Schema::table('logistics_carriers', fn (Blueprint $table) => $table->dropColumn('display_name'));
    }
};
