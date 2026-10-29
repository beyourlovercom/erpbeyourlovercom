<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shipout_dispatch_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('order_no', 60);
            $table->string('provider_warehouse_id', 64);
            $table->char('payload_hash', 64);
            $table->string('status', 32)->default('uncertain');
            $table->string('provider_order_id', 128)->nullable();
            $table->string('result_code', 64)->nullable();
            $table->timestamps();
            $table->unique('order_id', 'shipout_once_per_erp_order');
            $table->unique('order_no', 'shipout_once_per_warehouse_order_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipout_dispatch_attempts');
    }
};
