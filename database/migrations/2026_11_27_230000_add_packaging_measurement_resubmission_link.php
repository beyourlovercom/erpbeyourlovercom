<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('packaging_measurement_records', function (Blueprint $table): void {
            $table->foreignId('resubmission_of_id')->nullable()->unique()
                ->constrained('packaging_measurement_records')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('packaging_measurement_records', function (Blueprint $table): void {
            $table->dropForeign(['resubmission_of_id']);
            $table->dropUnique(['resubmission_of_id']);
            $table->dropColumn('resubmission_of_id');
        });
    }
};
