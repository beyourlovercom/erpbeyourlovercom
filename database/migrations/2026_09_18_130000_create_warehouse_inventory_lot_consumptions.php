<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_inventory_lot_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('stock_status', 16);
            $table->date('putaway_date');
            $table->foreignId('source_sync_batch_id')->constrained('inventory_sync_batches')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedSmallInteger('aging_months_charged')->default(0);
            $table->decimal('unit_storage_cost_cny', 10, 2)->default(0);
            $table->decimal('allocated_storage_cost_cny', 14, 2)->default(0);
            $table->timestamp('consumed_at');
            $table->string('lot_business_key', 64);
            $table->string('source_reference')->unique();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['shipment_id', 'order_item_id', 'source_sync_batch_id', 'lot_business_key'],
                'wh_lot_consumption_business_key_uq',
            );
            $table->index(['warehouse_id', 'product_id', 'source_sync_batch_id'], 'wh_lot_consumption_availability_idx');
            $table->index(['order_id', 'consumed_at'], 'wh_lot_consumption_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_inventory_lot_consumptions');
    }
};
