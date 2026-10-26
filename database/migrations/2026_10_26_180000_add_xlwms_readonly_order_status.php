<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('xlwms_dispatch_attempts', function (Blueprint $table): void {
            $table->string('oms_status_code', 20)->nullable();
            $table->timestamp('oms_status_checked_at')->nullable();
            $table->string('oms_tracking_no', 120)->nullable();
            $table->string('oms_status_failure_code', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('xlwms_dispatch_attempts', function (Blueprint $table): void {
            $table->dropColumn([
                'oms_status_code', 'oms_status_checked_at',
                'oms_tracking_no', 'oms_status_failure_code',
            ]);
        });
    }
};
