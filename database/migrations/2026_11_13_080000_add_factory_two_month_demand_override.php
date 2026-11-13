<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_inventory', function (Blueprint $table): void {
            $table->unsignedInteger('two_month_demand_override')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_inventory', function (Blueprint $table): void {
            $table->dropColumn('two_month_demand_override');
        });
    }
};
