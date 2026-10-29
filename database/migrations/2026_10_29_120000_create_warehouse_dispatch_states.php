<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('warehouse_dispatch_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->unique()->constrained('shipments')->cascadeOnDelete();
            $table->foreignId('order_id')->index()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->string('provider', 32);
            $table->string('status', 40)->index();
            $table->string('provider_order_no', 100)->nullable();
            $table->string('provider_status_code', 16)->nullable();
            $table->string('failure_code', 90)->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
        Schema::create('warehouse_dispatch_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('previous_status', 40)->nullable();
            $table->string('status', 40);
            $table->string('provider_order_no', 100)->nullable();
            $table->string('event_code', 90);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['shipment_id','created_at']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('warehouse_dispatch_events');
        Schema::dropIfExists('warehouse_dispatch_states');
    }
};
