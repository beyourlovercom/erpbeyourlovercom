<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_packaging_profile_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_packaging_profile_id')->constrained('product_packaging_profiles')->cascadeOnDelete();
            $table->string('kind', 24);
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['product_packaging_profile_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_packaging_profile_media');
    }
};
