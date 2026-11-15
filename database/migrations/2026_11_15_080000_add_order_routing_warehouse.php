<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Staff routing for work queues; not a physical warehouse allocation.
            $table->string('routing_warehouse_code', 32)->nullable()->after('workflow_status');
            $table->unsignedInteger('routing_revision')->default(0)->after('routing_warehouse_code');
            $table->index('routing_warehouse_code');
        });
        Schema::create('order_warehouse_route_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_code', 32)->nullable();
            $table->string('to_code', 32);
            $table->unsignedInteger('revision');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['order_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_warehouse_route_histories');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['routing_warehouse_code']);
            $table->dropColumn(['routing_warehouse_code', 'routing_revision']);
        });
    }
};
