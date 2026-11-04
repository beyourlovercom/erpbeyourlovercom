<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overdue_inventory_exclusions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->boolean('is_excluded')->default(false);
            $table->string('reason', 160)->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('is_excluded');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overdue_inventory_exclusions');
    }
};
