<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_stock_read_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->string('provider_sku', 128);
            $table->unsignedInteger('last_read_raw_qty')->nullable();
            $table->unsignedInteger('last_read_stock_qty')->nullable();
            $table->unsignedInteger('post_read_adjustment_qty')->default(0);
            $table->timestamp('last_read_stock_at')->nullable();
            $table->string('last_read_stock_source', 24)->nullable();
            $table->string('last_read_stock_status', 40)->default('unread');
            $table->timestamp('last_read_failed_at')->nullable();
            $table->string('last_read_error_code', 80)->nullable();
            $table->string('snapshot_id', 64)->nullable();
            $table->timestamps();
            $table->unique(['warehouse_id', 'provider_sku'], 'stock_read_unique_provider_sku');
            $table->index(['warehouse_id', 'product_id'], 'stock_read_product_lookup');
        });
        // Append-only read evidence; the current reference row is a projection,
        // never the only record of a failed read or failed mirror apply.
        Schema::create('warehouse_stock_read_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->string('provider_sku', 128)->nullable();
            $table->string('event_type', 32);
            $table->string('status', 40);
            $table->unsignedInteger('raw_quantity')->nullable();
            $table->unsignedInteger('reference_quantity')->nullable();
            $table->string('source', 24)->nullable();
            $table->char('snapshot_id', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['warehouse_id', 'occurred_at']);
        });
        Schema::create('warehouse_stock_read_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('provider_sku', 128);
            $table->char('event_hash', 64);
            $table->string('event_type', 32)->default('provider_shipped');
            $table->unsignedInteger('quantity');
            $table->timestamp('shipped_at_utc');
            $table->string('outcome', 32);
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('snapshot_at')->nullable();
            $table->timestamps();
            $table->unique(['warehouse_id', 'product_id', 'event_hash'], 'stock_read_unique_shipped_event');
            $table->index(['warehouse_id', 'product_id', 'shipped_at_utc'], 'stock_read_shipments_by_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_stock_read_adjustments');
        Schema::dropIfExists('warehouse_stock_read_events');
        Schema::dropIfExists('warehouse_stock_read_references');
    }
};
