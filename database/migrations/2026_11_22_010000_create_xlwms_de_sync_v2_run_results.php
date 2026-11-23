<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('xlwms_inventory_manual_runs', function (Blueprint $table): void {
            $table->string('run_kind', 24)->default('refresh');
            $table->unsignedBigInteger('resume_from_run_id')->nullable();
            $table->unsignedInteger('successful_sku_count')->default(0);
            $table->unsignedInteger('updated_sku_count')->default(0);
            $table->unsignedInteger('unchanged_sku_count')->default(0);
            $table->unsignedInteger('failed_sku_count')->default(0);
            $table->unsignedInteger('missing_sku_count')->default(0);
            $table->string('outcome', 24)->default('pending');
        });
        Schema::create('xlwms_inventory_manual_run_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('xlwms_inventory_manual_runs')->cascadeOnDelete();
            $table->string('provider_sku', 128);
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('outcome', 24);
            $table->unsignedInteger('oms_quantity')->nullable();
            $table->unsignedInteger('erp_before')->nullable();
            $table->unsignedInteger('erp_after')->nullable();
            $table->integer('delta')->nullable();
            $table->string('error_code', 128)->nullable();
            $table->boolean('write_verified')->default(false);
            $table->timestamps();
            $table->unique(['run_id', 'provider_sku'], 'xlwms_de_manual_run_row_identity');
            $table->index(['run_id', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xlwms_inventory_manual_run_rows');
        Schema::table('xlwms_inventory_manual_runs', function (Blueprint $table): void {
            $table->dropColumn(['run_kind', 'resume_from_run_id', 'successful_sku_count',
                'updated_sku_count', 'unchanged_sku_count', 'failed_sku_count',
                'missing_sku_count', 'outcome']);
        });
    }
};
