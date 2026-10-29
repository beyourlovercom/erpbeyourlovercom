<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('xlwms_reshipments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('original_order_id')->constrained('orders')->cascadeOnDelete();
            $t->foreignId('original_shipment_id')->constrained('shipments')->cascadeOnDelete();
            $t->foreignId('warehouse_id')->constrained('warehouses');
            $t->string('request_id',36)->unique();
            $t->string('third_order_no',100)->unique();
            $t->string('original_oms_order_no',100)->nullable();
            $t->string('oms_order_no',100)->nullable()->unique();
            $t->string('status',48)->index();
            $t->string('reason',180);
            $t->string('memo',500);
            $t->char('payload_fingerprint',64);
            $t->json('sku_quantity_snapshot');
            $t->unsignedBigInteger('requested_by');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('oms_checked_at')->nullable();
            $t->string('last_error_code',90)->nullable();
            $t->timestamps();
            $t->index(['original_order_id','created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('xlwms_reshipments'); }
};
