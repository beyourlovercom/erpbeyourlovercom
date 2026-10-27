<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_parameter_submissions', function (Blueprint $table): void {
            $table->foreignId('task_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Existing general submissions must not be deleted or silently reassigned.
        if (\Illuminate\Support\Facades\DB::table('product_parameter_submissions')->whereNull('task_id')->exists()) {
            throw new \RuntimeException('General Product360 submissions exist; cannot restore non-null task_id');
        }
        Schema::table('product_parameter_submissions', function (Blueprint $table): void {
            $table->foreignId('task_id')->nullable(false)->change();
        });
    }
};
