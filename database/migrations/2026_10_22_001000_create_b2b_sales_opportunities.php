<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b2b_sales_opportunities', function (Blueprint $t): void {
            $t->id();
            $t->string('opportunity_number', 40)->unique();
            $t->foreignId('organization_id')->constrained('b2b_organizations')->restrictOnDelete();
            $t->foreignId('case_id')->nullable()->constrained('b2b_cases')->restrictOnDelete()->unique();
            $t->foreignId('contact_id')->nullable()->constrained('b2b_contacts')->restrictOnDelete();
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('title');
            $t->string('status', 16)->default('open');
            $t->string('pipeline_stage', 24)->default('discovery');
            $t->char('currency', 3)->default('USD');
            $t->unsignedBigInteger('expected_value_micros')->nullable();
            $t->unsignedSmallInteger('probability_bps')->default(1000);
            $t->date('expected_close_date')->nullable();
            $t->timestamp('next_follow_up_at')->nullable();
            $t->timestamp('won_at')->nullable();
            $t->timestamp('lost_at')->nullable();
            $t->string('lost_reason_code', 64)->nullable();
            $t->timestamps();
            $t->index(['owner_id', 'status', 'next_follow_up_at'], 'b2b_opp_owner_followup');
            $t->index(['organization_id', 'status', 'pipeline_stage'], 'b2b_opp_org_stage');
        });

        Schema::create('b2b_sales_stage_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('opportunity_id')->constrained('b2b_sales_opportunities')->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('from_stage', 24)->nullable();
            $t->string('to_stage', 24);
            $t->string('source', 24)->default('manual');
            $t->string('reason', 191)->nullable();
            $t->timestamp('entered_at');
            $t->timestamps();
            $t->index(['opportunity_id', 'entered_at'], 'b2b_sales_event_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_sales_stage_events');
        Schema::dropIfExists('b2b_sales_opportunities');
    }
};
