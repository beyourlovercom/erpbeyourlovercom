<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('warehouse_openapi_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->string('provider', 32);
            $table->text('app_key_encrypted');
            $table->text('app_secret_encrypted');
            $table->string('oms_warehouse_code', 128)->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
            $table->unique(['warehouse_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_openapi_connections');
    }
};
