<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_warehouse_skus', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('sku', 128);
            $table->boolean('is_active')->default(true);
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('verification_note', 500)->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'warehouse_id'], 'product_warehouse_sku_unique');
            $table->unique(['warehouse_id', 'sku'], 'product_warehouse_sku_physical_unique');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->string('final_sku', 128)->nullable()->after('resolved_fulfillment_sku');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('final_sku'));
        Schema::dropIfExists('product_warehouse_skus');
    }
};
