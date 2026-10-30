<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('xlwms_web_draft_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->string('attempt_ref', 120)->unique();
            $table->string('request_fingerprint', 64);
            $table->string('state', 50);
            $table->string('oms_order_no', 100)->nullable();
            $table->string('duplicate_match_field', 50)->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->foreignId('requested_by')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xlwms_web_draft_attempts');
    }
};
