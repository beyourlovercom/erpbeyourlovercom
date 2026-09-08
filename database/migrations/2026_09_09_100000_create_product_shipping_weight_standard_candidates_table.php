<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_shipping_weight_standard_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('suggested_weight_kg', 10, 4);
            $table->string('algorithm_version', 64);
            $table->string('workflow_status', 16)->default('pending'); // pending|adopted|rejected|superseded
            $table->string('confidence_status', 32); // high_confidence|review_required
            $table->unsignedInteger('raw_sample_count');
            $table->unsignedInteger('eligible_sample_count');
            $table->unsignedInteger('gross_outlier_count');
            $table->decimal('eligible_min_kg', 10, 4)->nullable();
            $table->decimal('eligible_max_kg', 10, 4)->nullable();
            $table->decimal('eligible_spread_ratio', 10, 6)->nullable();
            $table->json('policy_json');
            $table->string('fingerprint', 64)->unique();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('rejection_note')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'workflow_status']);
            $table->index('confidence_status');
        });

        Schema::create('product_shipping_weight_standard_candidate_evidences', function (Blueprint $table): void {
            $table->foreignId('candidate_id')
                ->constrained('product_shipping_weight_standard_candidates')
                ->cascadeOnDelete();
            $table->foreignId('product_weight_evidence_id')
                ->constrained('product_weight_evidences')
                ->cascadeOnDelete();
            $table->boolean('is_eligible');
            $table->boolean('is_gross_outlier');
            $table->decimal('deviation_ratio', 10, 6)->nullable();

            $table->unique(['candidate_id', 'product_weight_evidence_id']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('shipping_weight_standard_candidate_id')
                ->nullable()
                ->after('shipping_weight_evidence_id')
                ->constrained('product_shipping_weight_standard_candidates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('shipping_weight_standard_candidate_id');
        });

        Schema::dropIfExists('product_shipping_weight_standard_candidate_evidences');
        Schema::dropIfExists('product_shipping_weight_standard_candidates');
    }
};
