<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['deleted_at', 'order_kind']);
        });
    }

    public function down(): void
    {
        // A schema-only rollback can safely retain the extra columns. Dropping
        // them with active trash rows would resurrect deleted orders on old code.
        if (Schema::hasColumn('orders', 'deleted_at')
            && DB::table('orders')->whereNotNull('deleted_at')->exists()) {
            throw new RuntimeException(
                'Cannot drop order trash columns while trashed orders exist. Restore or reconcile them first.'
            );
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['deleted_at', 'order_kind']);
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropSoftDeletes();
        });
    }
};
