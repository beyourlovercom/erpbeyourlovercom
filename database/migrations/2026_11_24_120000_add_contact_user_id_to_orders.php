<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('contact_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index('contact_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['contact_user_id']);
            $table->dropIndex(['contact_user_id']);
            $table->dropColumn('contact_user_id');
        });
    }
};
