<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('operational_archived_at')->nullable()->after('archived_at');
            $table->foreignId('operational_archived_by')->nullable()->after('operational_archived_at')->constrained('users')->nullOnDelete();
            $table->index(['operational_archived_at', 'order_kind']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['operational_archived_by']);
            $table->dropIndex(['operational_archived_at', 'order_kind']);
            $table->dropColumn(['operational_archived_at', 'operational_archived_by']);
        });
    }
};
