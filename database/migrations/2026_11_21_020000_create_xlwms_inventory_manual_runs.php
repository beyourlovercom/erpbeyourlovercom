<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xlwms_inventory_manual_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->unsignedBigInteger('operator_user_id');
            $table->string('status', 32)->default('queued');
            $table->boolean('apply_requested')->default(false);
            $table->unsignedBigInteger('snapshot_id')->nullable();
            $table->unsignedInteger('provider_sku_count')->default(0);
            $table->unsignedInteger('mapped_sku_count')->default(0);
            $table->unsignedInteger('unmatched_sku_count')->default(0);
            $table->unsignedInteger('blocked_sku_count')->default(0);
            $table->string('error_code', 100)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['warehouse_id', 'id']);
            $table->index(['warehouse_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xlwms_inventory_manual_runs');
    }
};
