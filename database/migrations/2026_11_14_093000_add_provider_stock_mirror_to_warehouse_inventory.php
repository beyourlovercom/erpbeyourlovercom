<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_inventory', function (Blueprint $table): void {
            $table->unsignedInteger('provider_available_quantity')->nullable();
            $table->timestamp('provider_stock_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_inventory', function (Blueprint $table): void {
            $table->dropColumn(['provider_available_quantity', 'provider_stock_synced_at']);
        });
    }
};
