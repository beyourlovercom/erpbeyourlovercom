<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kols', function (Blueprint $table): void {
            $table->text('shipping_customs_id')->nullable()->after('shipping_country');
            // Existing short country codes continue to work, but operators may type full names.
            $table->string('shipping_country', 128)->nullable()->change();
        });
        Schema::table('order_addresses', function (Blueprint $table): void {
            $table->text('recipient_customs_id')->nullable();
        });
    }

    public function down(): void
    {
        // Refuse to destroy sensitive customs IDs in rollbacks.
        if (DB::table('kols')->whereNotNull('shipping_customs_id')->exists()
            || DB::table('order_addresses')->whereNotNull('recipient_customs_id')->exists()
            || DB::table('kols')->whereRaw('LENGTH(shipping_country) > 8')->exists()) {
            throw new RuntimeException('Refusing to drop saved customs IDs or truncate shipping country values.');
        }
        Schema::table('order_addresses', fn (Blueprint $table) => $table->dropColumn('recipient_customs_id'));
        Schema::table('kols', function (Blueprint $table): void {
            $table->dropColumn('shipping_customs_id');
            $table->string('shipping_country', 8)->nullable()->change();
        });
    }
};
