<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('xlwms_dispatch_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->string('third_order_no', 100)->unique();
            $table->string('state', 40);
            $table->string('request_fingerprint', 64);
            $table->string('oms_order_no', 100)->nullable();
            $table->string('failure_code', 90)->nullable();
            $table->foreignId('requested_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xlwms_dispatch_attempts');
    }
};
