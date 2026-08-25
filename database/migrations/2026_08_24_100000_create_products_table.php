<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('woocommerce_product_id')->unique();
            $table->string('product_type', 64)->default('simple');
            $table->string('sku', 128)->nullable()->unique();
            $table->string('gtin', 128)->nullable();
            $table->string('name', 512);
            $table->decimal('weight_kg', 10, 4)->nullable();
            $table->decimal('sale_price', 18, 2)->nullable();
            $table->decimal('regular_price', 18, 2)->nullable();
            $table->timestamps();

            $table->index('sku');
            $table->index('name');
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('product_id')->nullable()->after('id')->constrained('products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_id');
        });
        Schema::dropIfExists('products');
    }
};
