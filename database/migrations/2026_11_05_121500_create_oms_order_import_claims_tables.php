<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oms_order_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->char('file_sha256', 64)->unique();
            $table->unsignedBigInteger('actor_id');
            $table->unsignedInteger('order_count');
            $table->timestamps();
            $table->index('actor_id');
        });
        Schema::create('oms_order_import_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('oms_order_import_batches')->cascadeOnDelete();
            $table->string('source_number_normalized', 128)->unique();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oms_order_import_claims');
        Schema::dropIfExists('oms_order_import_batches');
    }
};
