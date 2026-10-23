<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('b2b_sales_opportunities', function (Blueprint $t): void {
            $t->text('lost_reason_note')->nullable();
            $t->timestamp('last_follow_up_at')->nullable();
        });

        Schema::create('b2b_sales_followups', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('opportunity_id')->constrained('b2b_sales_opportunities')->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('stage_key', 24);
            $t->string('channel', 32)->default('note');
            $t->string('outcome', 64)->nullable();
            $t->text('summary');
            $t->text('next_plan')->nullable();
            $t->timestamp('happened_at');
            $t->timestamp('next_follow_up_at')->nullable();
            $t->timestamps();
            $t->index(['opportunity_id','stage_key','happened_at'], 'b2b_sales_fu_stage_time');
        });

        Schema::create('b2b_sales_followup_attachments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('follow_up_id')->constrained('b2b_sales_followups')->restrictOnDelete();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('disk', 32)->default('local');
            $t->string('path', 1024);
            $t->string('original_name', 255);
            $t->string('mime_type', 127)->nullable();
            $t->unsignedBigInteger('size_bytes')->default(0);
            $t->timestamps();
            $t->index(['follow_up_id','created_at'], 'b2b_sales_fu_attach_time');
        });

        Schema::create('b2b_sales_quotes', function (Blueprint $t): void {
            $t->id();
            $t->string('quote_number', 40)->unique();
            $t->foreignId('opportunity_id')->constrained('b2b_sales_opportunities')->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('status', 24)->default('draft');
            $t->char('currency', 3)->default('USD');
            $t->unsignedBigInteger('subtotal_micros')->default(0);
            $t->unsignedBigInteger('discount_micros')->default(0);
            $t->unsignedBigInteger('tax_micros')->default(0);
            $t->unsignedBigInteger('total_micros')->default(0);
            $t->date('valid_until')->nullable();
            $t->json('line_items')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['opportunity_id','version'], 'b2b_sales_quote_version');
            $t->index(['opportunity_id','status'], 'b2b_sales_quote_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_sales_quotes');
        Schema::dropIfExists('b2b_sales_followup_attachments');
        Schema::dropIfExists('b2b_sales_followups');
        Schema::table('b2b_sales_opportunities', function (Blueprint $t): void {
            $t->dropColumn(['lost_reason_note','last_follow_up_at']);
        });
    }
};
