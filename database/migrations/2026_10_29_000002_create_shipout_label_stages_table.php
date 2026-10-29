<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shipout_label_stages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('actor_id');
            $table->char('token_sha256', 64)->unique();
            $table->longText('encrypted_pdf_b64');
            $table->string('tracking_number', 128);
            $table->unsignedTinyInteger('carrier_id');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['order_id','actor_id','expires_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('shipout_label_stages'); }
};
