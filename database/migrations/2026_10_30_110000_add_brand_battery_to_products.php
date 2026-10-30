<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('brand', 255)->nullable();
            // Default to the more restrictive battery classification for shipping.
            $table->boolean('has_battery')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['brand', 'has_battery']);
        });
    }
};
