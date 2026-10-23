<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Finance-owned source of truth only for B2B trade, never Woo OrderFinancial.
        Schema::create('finance_b2b_trade_cash_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('trade_order_id')->constrained('b2b_trade_orders')->restrictOnDelete();
            $t->foreignId('receipt_evidence_id')->nullable()->unique()
                ->constrained('b2b_trade_receipt_evidence')->restrictOnDelete();
            $t->foreignId('refund_of_id')->nullable()->constrained('finance_b2b_trade_cash_events')->restrictOnDelete();
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('event_type', 16); // receipt | refund
            $t->string('source', 48); // bank_transfer | provider_reference
            $t->string('external_reference', 191);
            $t->char('currency', 3);
            $t->unsignedBigInteger('amount_micros');
            $t->date('value_date');
            $t->string('attestation_method', 48); // manual_bank_statement | manual_provider_statement
            $t->text('confirmation_note');
            $t->timestamp('confirmed_at');
            $t->timestamps();
            $t->unique(['source','external_reference'], 'finance_b2b_cash_external_unique');
            $t->index(['trade_order_id','event_type','confirmed_at'], 'finance_b2b_trade_cash_order');
        });
        DB::table('permissions')->updateOrInsert(
            ['key'=>'finance.b2b_cash_confirm'],
            ['label'=>'财务确认 B2B 贸易订单银行/支付实际收款与退款', 'created_at'=>now(), 'updated_at'=>now()]
        );
        $admin = DB::table('roles')->where('key','role_preset_administrator')->value('id');
        if ($admin !== null) {
            $perm = DB::table('permissions')->where('key','finance.b2b_cash_confirm')->value('id');
            DB::table('permission_role')->updateOrInsert(['role_id'=>$admin,'permission_id'=>$perm],[]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_b2b_trade_cash_events');
        $ids = DB::table('permissions')->where('key','finance.b2b_cash_confirm')->pluck('id');
        DB::table('permission_role')->whereIn('permission_id',$ids)->delete();
        DB::table('permission_user')->whereIn('permission_id',$ids)->delete();
        DB::table('permissions')->whereIn('id',$ids)->delete();
    }
};
