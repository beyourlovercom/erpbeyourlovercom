<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('xlwms_reshipments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('original_order_id')->constrained('orders')->restrictOnDelete();
            $t->foreignId('original_shipment_id')->constrained('shipments')->restrictOnDelete();
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
            $t->string('cost_status',32)->default('pending');
            $t->decimal('additional_cost_cny',14,2)->nullable();
            $t->string('cost_evidence_reference',100)->nullable();
            $t->timestamps();
            $t->index(['original_order_id','created_at']);
        });
        Schema::create('xlwms_reshipment_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('reshipment_id')->constrained('xlwms_reshipments')->restrictOnDelete();
            $t->string('previous_status',48)->nullable();
            $t->string('status',48);
            $t->string('event_code',90);
            $t->unsignedBigInteger('actor_id')->nullable();
            $t->string('oms_order_no',100)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['reshipment_id','created_at']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('xlwms_reshipment_events');
        Schema::dropIfExists('xlwms_reshipments');
    }
};
