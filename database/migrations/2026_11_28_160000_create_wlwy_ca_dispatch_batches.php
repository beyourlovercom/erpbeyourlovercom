<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wlwy_ca_batches', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignId('operator_user_id')->constrained('users');
            $t->string('status', 32);
            $t->boolean('dry_run');
            $t->timestamps();
        });
        Schema::create('wlwy_ca_batch_items', function (Blueprint $t): void {
            $t->id();
            $t->uuid('batch_id');
            $t->foreign('batch_id')->references('id')->on('wlwy_ca_batches')->restrictOnDelete();
            $t->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $t->unsignedBigInteger('shipment_id');
            $t->string('order_no', 100);
            $t->string('reference', 50);
            $t->char('payload_hash', 64);
            $t->string('status', 32)->index();
            $t->string('result_code', 90)->nullable();
            $t->string('provider_order_id', 100)->nullable();
            $t->boolean('dry_run');
            $t->timestamp('tracking_checked_at')->nullable();
            $t->timestamps();
            $t->index(['order_id', 'dry_run']);
            $t->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wlwy_ca_batch_items');
        Schema::dropIfExists('wlwy_ca_batches');
    }
};
