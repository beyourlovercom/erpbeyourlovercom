<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('workflow_status', 64)->nullable()->after('order_status');
            $table->unsignedInteger('workflow_revision')->default(0)->after('workflow_status');
            $table->index(['workflow_status', 'workflow_revision']);
        });

        Schema::create('order_workflow_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 64)->nullable();
            $table->string('to_status', 64);
            $table->unsignedInteger('revision');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['order_id', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_workflow_status_histories');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['workflow_status', 'workflow_revision']);
            $table->dropColumn(['workflow_status', 'workflow_revision']);
        });
    }
};
