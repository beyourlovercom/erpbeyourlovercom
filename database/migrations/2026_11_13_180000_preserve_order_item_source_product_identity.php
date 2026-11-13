<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('source_product_id')->nullable()->after('product_id')->index();
            $table->string('source_product_summary', 255)->nullable()->after('product_summary');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex(['source_product_id']);
            $table->dropColumn(['source_product_id', 'source_product_summary']);
        });
    }
};
