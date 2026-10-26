<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('warehouse_sku_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('erp_sku', 128);
            $table->string('erp_sku_norm', 128);
            $table->string('oms_sku', 128);
            // New proposals never affect fulfillment until formally confirmed.
            $table->string('status', 16)->default('draft');
            $table->text('evidence_note')->nullable();
            $table->foreignId('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['warehouse_id', 'provider', 'erp_sku_norm'], 'warehouse_sku_mapping_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_sku_mappings');
    }
};
