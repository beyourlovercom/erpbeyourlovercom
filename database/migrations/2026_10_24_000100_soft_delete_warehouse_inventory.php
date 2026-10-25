<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('warehouse_inventory', function (Blueprint $table): void {
            $table->softDeletes();
            $table->index(['warehouse_id', 'deleted_at'], 'warehouse_inventory_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('warehouse_inventory', function (Blueprint $table): void {
            $table->dropIndex('warehouse_inventory_active_idx');
            $table->dropSoftDeletes();
        });
    }
};
