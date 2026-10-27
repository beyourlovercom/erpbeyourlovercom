<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_parameter_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('enabled')->default(false);
            $table->boolean('is_required')->default(false);
            $table->json('required_fields')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['assigned_user_id', 'enabled']);
        });

        Schema::create('product_parameter_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('product_parameter_tasks')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 16)->default('draft');
            $table->json('measurements')->nullable();
            $table->json('accessories')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'status']);
            $table->index(['created_by', 'status']);
        });

        Schema::create('product_parameter_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submission_id')->constrained('product_parameter_submissions')->cascadeOnDelete();
            $table->string('disk', 32)->default('local');
            $table->string('path', 512);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
        });

        Schema::create('product_parameter_accessories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('approved_submission_id')->constrained('product_parameter_submissions')->cascadeOnDelete();
            $table->string('name', 200);
            $table->decimal('quantity', 12, 3);
            $table->string('unit', 32);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_parameter_accessories');
        Schema::dropIfExists('product_parameter_media');
        Schema::dropIfExists('product_parameter_submissions');
        Schema::dropIfExists('product_parameter_tasks');
    }
};
