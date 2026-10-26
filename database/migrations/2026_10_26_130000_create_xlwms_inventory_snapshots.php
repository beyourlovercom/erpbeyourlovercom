<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('xlwms_inventory_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('wh_code', 128);
            $table->string('checksum', 64);
            $table->string('status', 24)->default('read_only'); // read_only | applied | blocked | not_ready | failed
            $table->timestamp('fetched_at');
            $table->timestamp('applied_at')->nullable();
            $table->unsignedInteger('provider_sku_count')->default(0);
            $table->unsignedInteger('mapped_sku_count')->default(0);
            $table->unsignedInteger('unmatched_sku_count')->default(0);
            $table->unsignedInteger('blocked_sku_count')->default(0);
            $table->json('summary')->nullable();
            $table->json('mismatches')->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();
            $table->unique(['warehouse_id', 'checksum'], 'xlwms_inventory_snapshot_identity');
            $table->index(['warehouse_id', 'fetched_at']);
        });

        Schema::create('xlwms_inventory_snapshot_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('xlwms_inventory_snapshots')->cascadeOnDelete();
            $table->string('provider_sku', 128);
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('resolution_status', 24); // matched | unmatched | ambiguous | blocked
            $table->unsignedInteger('oms_total')->nullable();
            $table->unsignedInteger('oms_available')->nullable();
            $table->unsignedInteger('oms_locked')->nullable();
            $table->unsignedInteger('oms_in_transit')->nullable();
            $table->string('mismatch_reason', 512)->nullable();
            $table->timestamps();
            $table->unique(['snapshot_id', 'provider_sku'], 'xlwms_inventory_snapshot_row_identity');
            $table->index(['product_id', 'resolution_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xlwms_inventory_snapshot_rows');
        Schema::dropIfExists('xlwms_inventory_snapshots');
    }
};
