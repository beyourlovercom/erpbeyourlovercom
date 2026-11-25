<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_collaboration_partners', function (Blueprint $table): void {
            $table->string('identity_prefix', 8)->nullable()->unique();
        });
        DB::table('order_collaboration_partners')->where('code', 'CHUNYI')->update(['identity_prefix' => 'CY']);
        DB::table('order_collaboration_partners')->where('code', 'AMAZON_JENNY')->update(['identity_prefix' => 'AJ']);
    }

    public function down(): void
    {
        Schema::table('order_collaboration_partners', function (Blueprint $table): void {
            $table->dropUnique(['identity_prefix']);
            $table->dropColumn('identity_prefix');
        });
    }
};
