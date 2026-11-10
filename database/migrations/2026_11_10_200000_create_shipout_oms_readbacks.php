<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipout_oms_readbacks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->string('provider_order_id', 100)->nullable();
            $table->integer('provider_status')->nullable();
            $table->string('state', 24);
            $table->string('reason_code', 100)->nullable();
            $table->timestamp('checked_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipout_oms_readbacks');
    }
};
