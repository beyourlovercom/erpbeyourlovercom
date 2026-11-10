<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_sku_aliases', function (Blueprint $table): void {
            // No existing alias is silently enabled for outbound SKU substitution.
            $table->timestamp('oms_dispatch_approved_at')->nullable();
            $table->string('oms_dispatch_canonical_sku', 128)->nullable();
            $table->foreignId('oms_dispatch_approved_by_user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_sku_aliases', function (Blueprint $table): void {
            $table->dropColumn([
                'oms_dispatch_approved_at',
                'oms_dispatch_canonical_sku',
                'oms_dispatch_approved_by_user_id',
            ]);
        });
    }
};
