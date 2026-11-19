<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Production has BV339A-P/Y but no BV339A master; seed exact approved variants.
        DB::table('products')->whereIn('sku', ['BV339A-P', 'BV339A-Y'])
            ->update(['has_liquid' => true]);
    }

    public function down(): void
    {
        // Preserve subsequent ERP-owned manual edits; schema rollback is in the prior migration.
    }
};
