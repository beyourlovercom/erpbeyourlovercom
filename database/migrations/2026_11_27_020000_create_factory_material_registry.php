<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_provider_snapshots', function (Blueprint $table): void {
            $table->string('provider_sku', 128)->nullable();
        });
        Schema::create('factory_material_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->date('stock_count_at')->unique();
            $table->string('file_sha256', 64)->unique();
            $table->string('filename', 255);
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('material_count');
            $table->unsignedBigInteger('factory_physical_qty');
            $table->unsignedBigInteger('warehouse_007_qty');
            $table->json('result')->nullable();
            $table->timestamps();
        });
        Schema::create('product_material_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('material_code', 128);
            $table->string('code_key', 128)->unique();
            $table->text('material_name');
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->string('binding_source', 64)->nullable();
            $table->text('binding_evidence')->nullable();
            $table->boolean('factory_stock_approved')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedInteger('factory_physical_qty')->nullable();
            $table->unsignedInteger('warehouse_007_qty')->nullable();
            $table->foreignId('last_snapshot_id')->nullable()->constrained('factory_material_snapshots')->restrictOnDelete();
            $table->timestamps();
            $table->index(['product_id', 'factory_stock_approved'], 'pmv_product_stock_approved_idx');
        });
        Schema::table('warehouse_inventory', function (Blueprint $table): void {
            $table->string('factory_native_status', 24)->nullable();
            $table->foreignId('factory_native_snapshot_id')->nullable()->constrained('factory_material_snapshots')->restrictOnDelete();
        });
        Schema::create('factory_material_snapshot_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('factory_material_snapshots')->restrictOnDelete();
            $table->foreignId('material_version_id')->constrained('product_material_versions')->restrictOnDelete();
            $table->unsignedInteger('source_row');
            $table->string('material_code', 128);
            $table->text('material_name');
            $table->unsignedInteger('factory_qty');
            $table->unsignedInteger('warehouse_007_qty');
            $table->foreignId('product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->boolean('stock_approved');
            $table->unique(['snapshot_id', 'material_version_id'], 'fmsr_snapshot_material_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factory_material_snapshot_rows');
        Schema::dropIfExists('product_material_versions');
        Schema::table('warehouse_inventory', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('factory_native_snapshot_id');
            $table->dropColumn('factory_native_status');
        });
        Schema::dropIfExists('factory_material_snapshots');
        Schema::table('product_provider_snapshots', fn (Blueprint $table) => $table->dropColumn('provider_sku'));
    }
};
