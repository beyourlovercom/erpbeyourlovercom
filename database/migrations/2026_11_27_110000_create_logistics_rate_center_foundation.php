<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logistics_rate_source_files', function (Blueprint $table): void {
            $table->id();
            $table->char('sha256', 64)->unique();
            $table->string('original_filename', 255);
            $table->string('private_storage_path', 512);
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('byte_size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('logistics_rate_books', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 96)->unique();
            $table->string('supplier_name', 160);
            $table->string('display_name', 160);
            $table->timestamps();
        });

        Schema::create('logistics_rate_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('book_id')->constrained('logistics_rate_books')->restrictOnDelete();
            $table->foreignId('source_file_id')->constrained('logistics_rate_source_files')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status', 24)->default('draft');
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['book_id', 'version_number'], 'lrv_book_version_unique');
            $table->index(['book_id', 'status', 'effective_from'], 'lrv_book_status_date_idx');
        });

        Schema::create('logistics_rate_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('version_id')->constrained('logistics_rate_versions')->restrictOnDelete();
            $table->string('component_key', 128);
            $table->string('warehouse_code', 32)->nullable();
            $table->string('carrier', 64);
            $table->string('service', 96);
            $table->char('destination_country', 2)->nullable();
            $table->string('destination_zone', 32)->nullable();
            $table->string('component_type', 32);
            $table->char('currency', 3);
            $table->string('weight_unit', 8);
            $table->string('pricing_mode', 32);
            $table->json('rule_spec');
            $table->string('source_sheet', 128);
            $table->string('source_cells', 128);
            $table->string('review_status', 24)->default('needs_review');
            $table->timestamps();
            $table->unique(['version_id', 'component_key'], 'lrc_version_key_unique');
            $table->index(['version_id', 'warehouse_code', 'carrier', 'destination_country'], 'lrc_lookup_idx');
        });

        Schema::create('logistics_rate_bands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('component_id')->constrained('logistics_rate_components')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->decimal('min_weight', 16, 6)->nullable();
            $table->decimal('max_weight', 16, 6)->nullable();
            $table->boolean('min_inclusive');
            $table->boolean('max_inclusive');
            $table->decimal('fixed_amount', 18, 6)->nullable();
            $table->decimal('per_weight_amount', 18, 6)->nullable();
            $table->decimal('per_extra_item_amount', 18, 6)->nullable();
            $table->string('source_cells', 128);
            $table->json('raw_values')->nullable();
            $table->unique(['component_id', 'sequence'], 'lrb_component_sequence_unique');
        });

        Schema::create('logistics_rate_zones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('version_id')->constrained('logistics_rate_versions')->restrictOnDelete();
            $table->string('zone_key', 128);
            $table->string('warehouse_code', 32);
            $table->string('carrier', 64);
            $table->string('service', 96);
            $table->char('destination_country', 2);
            $table->string('postal_match_type', 16);
            $table->string('postal_start', 32);
            $table->string('postal_end', 32)->nullable();
            $table->string('zone_label', 32);
            $table->string('review_status', 24)->default('needs_review');
            $table->string('source_sheet', 128);
            $table->string('source_cells', 128);
            $table->timestamps();
            $table->unique(['version_id', 'zone_key'], 'lrz_version_key_unique');
            $table->index(['version_id', 'warehouse_code', 'carrier', 'destination_country'], 'lrz_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logistics_rate_zones');
        Schema::dropIfExists('logistics_rate_bands');
        Schema::dropIfExists('logistics_rate_components');
        Schema::dropIfExists('logistics_rate_versions');
        Schema::dropIfExists('logistics_rate_books');
        Schema::dropIfExists('logistics_rate_source_files');
    }
};
