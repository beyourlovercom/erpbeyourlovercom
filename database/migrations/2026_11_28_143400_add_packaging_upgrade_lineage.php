<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('packaging_measurement_records',function(Blueprint $table){
            $table->foreignId('upgrade_from_record_id')->nullable()->constrained('packaging_measurement_records')->restrictOnDelete();
            $table->json('upgrade_plan')->nullable();
            $table->timestamp('standard_adopted_at')->nullable();
            $table->json('upgrade_quote_snapshot')->nullable();
        });
    }
    public function down(): void {
        Schema::table('packaging_measurement_records',function(Blueprint $table){
            $table->dropConstrainedForeignId('upgrade_from_record_id');
            $table->dropColumn(['upgrade_plan','standard_adopted_at','upgrade_quote_snapshot']);
        });
    }
};
