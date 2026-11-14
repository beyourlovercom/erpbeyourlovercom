<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // A partner fulfillment identity, not a physical inventory claim.
        if (DB::table('warehouses')->where('code', 'TINGON')->exists()) {
            return;
        }
        DB::table('warehouses')->insert([
            'slug' => 'tingon',
            'code' => 'TINGON',
            'name' => '合作-Tingon',
            'country_code' => null,
            'type' => 'warehouse',
            'ownership_type' => 'partner',
            'is_domestic' => false,
            'is_active' => true,
            'priority' => 75,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Keep historical shipment and warehouse references intact.
    }
};
