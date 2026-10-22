<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b2b_organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('display_name', 255);
            $table->char('country_code', 2)->nullable();
            $table->string('website_url', 500)->nullable();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('stage', 32)->default('prospect');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['owner_id', 'archived_at', 'created_at'], 'b2b_org_owner_active_idx');
            $table->index(['country_code', 'stage'], 'b2b_org_country_stage_idx');
        });

        Schema::create('b2b_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('b2b_organizations')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('job_title', 191)->nullable();
            $table->string('email_normalized', 254)->nullable();
            $table->string('phone', 64)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->unique(['organization_id', 'email_normalized'], 'b2b_contacts_org_email_uniq');
            $table->index(['organization_id', 'is_primary'], 'b2b_contacts_org_primary_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_contacts');
        Schema::dropIfExists('b2b_organizations');
    }
};
