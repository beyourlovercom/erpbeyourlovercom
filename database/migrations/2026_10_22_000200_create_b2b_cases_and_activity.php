<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b2b_cases', function (Blueprint $table): void {
            $table->id();
            $table->string('case_number', 64)->nullable()->unique();
            $table->foreignId('organization_id')->nullable()->constrained('b2b_organizations')->cascadeOnDelete();
            $table->foreignId('primary_contact_id')->nullable()->constrained('b2b_contacts')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 255);
            $table->string('source_channel', 32)->default('manual');
            $table->string('source_connection_key', 127)->nullable();
            $table->string('source_external_id', 191)->nullable();
            $table->char('source_dedupe_key', 64)->nullable()->unique();
            $table->string('source_site_key', 127)->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('product_interest', 255)->nullable();
            $table->unsignedInteger('requested_quantity')->nullable();
            $table->string('inquiry_kind', 48)->default('wholesale');
            $table->string('priority', 16)->default('normal');
            $table->string('case_status', 32)->default('open');
            $table->string('case_stage', 32)->default('new');
            $table->string('handling_state', 32)->default('waiting');
            $table->timestamp('first_inbound_at');
            $table->timestamp('last_inbound_at');
            $table->timestamp('first_action_at')->nullable();
            $table->timestamp('last_action_at')->nullable();
            $table->timestamp('attention_since_at')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('loss_reason_code', 64)->nullable();
            $table->timestamps();
            $table->index(['owner_id', 'case_status', 'last_inbound_at'], 'b2b_case_owner_status_idx');
            $table->index(['organization_id', 'case_status'], 'b2b_case_org_status_idx');
            $table->index(['handling_state', 'attention_since_at'], 'b2b_case_waiting_idx');
        });

        Schema::create('b2b_case_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('b2b_cases')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('activity_type', 64);
            $table->text('body_text')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['case_id', 'occurred_at'], 'b2b_activity_case_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_case_activities');
        Schema::dropIfExists('b2b_cases');
    }
};
