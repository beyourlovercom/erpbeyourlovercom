<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xlwms_inventory_unverified_ages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('unknown_age_quantity')->default(0);
            $table->foreignId('last_snapshot_id')->nullable()->constrained('xlwms_inventory_snapshots')->nullOnDelete();
            $table->timestamps();
            $table->unique(['warehouse_id', 'product_id'], 'xlwms_unverified_age_warehouse_product_uq');
        });

        Schema::create('xlwms_inventory_lot_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('xlwms_inventory_snapshots')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedInteger('qoh_before');
            $table->unsignedInteger('qoh_after');
            $table->unsignedInteger('dated_lot_quantity_before');
            $table->unsignedInteger('dated_lot_quantity_after');
            $table->unsignedInteger('unknown_age_before');
            $table->unsignedInteger('unknown_age_after');
            $table->json('physical_legacy_mismatch');
            $table->json('lot_adjustments');
            $table->string('source_checksum', 64);
            $table->timestamps();
            $table->unique(['snapshot_id', 'product_id'], 'xlwms_lot_reconciliation_snapshot_product_uq');
            $table->index(['warehouse_id', 'product_id'], 'xlwms_lot_reconciliation_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xlwms_inventory_lot_reconciliations');
        Schema::dropIfExists('xlwms_inventory_unverified_ages');
    }
};
