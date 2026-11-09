<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipout_production_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('operator_user_id');
            $table->string('status', 24)->default('queued');
            $table->boolean('dry_run')->default(false);
            $table->unsignedInteger('total');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::create('shipout_production_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('batch_id')->index();
            $table->unsignedBigInteger('order_id')->index();
            $table->string('order_no', 90);
            $table->string('status', 24)->default('queued');
            $table->string('result_code', 100)->nullable();
            $table->string('provider_order_id', 100)->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipout_production_batch_items');
        Schema::dropIfExists('shipout_production_batches');
    }
};
