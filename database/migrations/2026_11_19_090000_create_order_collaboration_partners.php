<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_collaboration_partners', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 160)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('collaboration_partner_id')->nullable()
                ->constrained('order_collaboration_partners')->restrictOnDelete();
        });
        DB::table('order_collaboration_partners')->insert([
            ['code' => 'CHUNYI', 'name' => '春意', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'AMAZON_JENNY', 'name' => '亚马逊 Jenny', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('collaboration_partner_id'));
        Schema::dropIfExists('order_collaboration_partners');
    }
};
