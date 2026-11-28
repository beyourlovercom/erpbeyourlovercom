<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('packaging_measurement_records', function (Blueprint $table): void {
            $table->string('photo_requirement_reason', 64)->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('packaging_measurement_records', function (Blueprint $table): void {
            $table->dropColumn('photo_requirement_reason');
        });
    }
};
