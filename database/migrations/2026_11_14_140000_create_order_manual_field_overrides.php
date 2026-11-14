<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('order_manual_field_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('field_key', 100);
            $table->foreignId('actor_id')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'field_key'], 'manual_order_field_key_unique');
        });
        Schema::table('audit_records', function (Blueprint $table): void {
            $table->index(['entity_type', 'entity_id', 'action'], 'audit_order_manual_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('audit_records', fn (Blueprint $table) => $table->dropIndex('audit_order_manual_lookup'));
        Schema::dropIfExists('order_manual_field_overrides');
    }
};
