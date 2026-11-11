<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Register warehouse identity only. Owner-supplied stock counts must
        // be recorded separately in inventory_adjustments, not seed data.
        if (DB::table('warehouses')->where('code', 'US-AMZ')->exists()) {
            return;
        }

        DB::table('warehouses')->insert([
            'slug' => 'us-amz',
            'code' => 'US-AMZ',
            'name' => '亚马逊仓·美',
            'country_code' => 'US',
            'type' => 'warehouse',
            'ownership_type' => 'external',
            'is_domestic' => false,
            'is_active' => true,
            'priority' => 27,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Do not delete operational warehouse history or counted stock.
    }
};
