<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('b2b_trade_orders', function (Blueprint $t): void {
            $t->id();
            $t->string('trade_order_number', 48)->unique();
            $t->foreignId('organization_id')->constrained('b2b_organizations')->restrictOnDelete();
            $t->foreignId('opportunity_id')->constrained('b2b_sales_opportunities')->restrictOnDelete();
            $t->foreignId('quote_id')->unique()->constrained('b2b_sales_quotes')->restrictOnDelete();
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status', 24)->default('draft'); // draft, approved, cancelled
            $t->char('currency', 3);
            $t->unsignedBigInteger('receivable_micros');
            $t->json('quote_snapshot');
            $t->date('payment_due_on')->nullable();
            $t->text('payment_terms')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'status', 'created_at'], 'trade_org_status');
            $t->index(['owner_id', 'status', 'created_at'], 'trade_owner_status');
        });
        Schema::create('b2b_trade_receipt_evidence', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('trade_order_id')->constrained('b2b_trade_orders')->restrictOnDelete();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('source', 48); // bank_transfer, manual, provider_ref
            $t->string('reference', 191);
            $t->char('currency', 3);
            $t->unsignedBigInteger('claimed_amount_micros');
            $t->date('claimed_paid_on')->nullable();
            $t->string('status', 24)->default('pending_review'); // pending_review,rejected,reviewed
            $t->text('review_note')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->unique(['source', 'reference'], 'trade_receipt_unique_ref');
            $t->index(['trade_order_id', 'status'], 'trade_receipt_order_status');
        });
        Schema::create('b2b_trade_audit_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('trade_order_id')->constrained('b2b_trade_orders')->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('event_type', 48);
            $t->json('context')->nullable();
            $t->timestamp('occurred_at');
            $t->timestamps();
            $t->index(['trade_order_id', 'occurred_at'], 'trade_audit_order_time');
        });

        foreach ([
            'b2b.trade.approve' => '审批 B2B 贸易订单',
            'b2b.receipts.review' => '复核 B2B 收款证据（不等于财务入账）',
        ] as $key => $label) {
            DB::table('permissions')->updateOrInsert(['key' => $key],
                ['label' => $label, 'created_at' => now(), 'updated_at' => now()]);
            $admin = DB::table('roles')->where('key', 'role_preset_administrator')->value('id');
            if ($admin !== null) {
                $permission = DB::table('permissions')->where('key', $key)->value('id');
                DB::table('permission_role')->updateOrInsert(
                    ['role_id' => $admin, 'permission_id' => $permission], []);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b_trade_audit_events');
        Schema::dropIfExists('b2b_trade_receipt_evidence');
        Schema::dropIfExists('b2b_trade_orders');
        $ids = DB::table('permissions')->whereIn('key', ['b2b.trade.approve','b2b.receipts.review'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permission_user')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
