<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xlwms_ny_dispatch_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('operator_user_id');
            $table->string('status', 32);
            $table->boolean('dry_run')->default(true);
            $table->unsignedInteger('total');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['operator_user_id', 'created_at']);
        });

        Schema::create('xlwms_ny_dispatch_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('batch_id');
            $table->unsignedBigInteger('order_id');
            $table->string('order_no', 120);
            $table->char('payload_hash', 64);
            $table->string('status', 32);
            $table->string('result_code', 100)->nullable();
            $table->timestamps();
            $table->foreign('batch_id')->references('id')
                ->on('xlwms_ny_dispatch_batches')->cascadeOnDelete();
            $table->unique(['batch_id', 'order_id']);
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xlwms_ny_dispatch_batch_items');
        Schema::dropIfExists('xlwms_ny_dispatch_batches');
    }
};
