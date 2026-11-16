<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_orders', function (Blueprint $table): void {
            $table->string('origin', 32)->default('stockout')->index();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable();
            $table->string('receipt_note', 500)->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable();
        });
        Schema::table('procurement_order_items', function (Blueprint $table): void {
            $table->unsignedInteger('received_quantity')->default(0);
            $table->string('received_sku', 128)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('procurement_order_items', fn (Blueprint $table) => $table->dropColumn(['received_quantity', 'received_sku']));
        Schema::table('procurement_orders', function (Blueprint $table): void {
            $table->dropIndex(['origin']);
            $table->dropColumn(['origin', 'received_at', 'received_by', 'receipt_note', 'released_at', 'released_by']);
        });
    }
};
