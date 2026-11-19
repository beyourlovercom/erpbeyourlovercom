<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('has_liquid')->default(false)->after('has_battery');
            $table->text('material_full_name')->nullable()->after('has_liquid');
        });

        // Initial Owner-confirmed liquid SKUs. All other products remain false.
        DB::table('products')->whereIn('sku', ['BY489A', 'BV339A'])
            ->update(['has_liquid' => true]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['has_liquid', 'material_full_name']);
        });
    }
};
