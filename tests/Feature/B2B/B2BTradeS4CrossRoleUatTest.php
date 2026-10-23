<?php

namespace Tests\Feature\B2B;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\B2B\Infrastructure\Models\B2BOrganization;
use App\Modules\B2B\Infrastructure\Models\B2BTradeOrder;
use App\Modules\B2B\Infrastructure\Models\B2BTradeReceiptEvidence;
use App\Modules\B2B\Sales\B2BSalesPipeline;
use App\Modules\Finance\Application\B2BTradeCashAccountingService;
use App\Modules\Finance\Infrastructure\Models\FinanceB2BTradeCashEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S4 full Laravel HTTP/session identity-switch acceptance matrix on isolated
 * in-memory SQLite. No physical browser login, payment gateway or bank API.
 */
final class B2BTradeS4CrossRoleUatTest extends TestCase
{
    use RefreshDatabase;

    private function roleUser(string $name, array $perms): User
    {
        $role=Role::create(['key'=>'s4-'.strtolower(str_replace(' ','-',$name)), 'label'=>$name]);
        foreach ($perms as $permission) {
            $right=Permission::firstOrCreate(['key'=>$permission],['label'=>$permission]);
            $role->permissions()->attach($right);
        }
        $user=User::factory()->create(['is_active'=>true,'name'=>$name]);
        $user->roles()->attach($role);
        return $user;
    }

    /** @return array{seller:User,manager:User,reviewer:User,finance:User,refundFinance:User,other:User,consumer:User} */
    private function team(): array
    {
        return [
            'seller'=>$this->roleUser('S4 Sales', ['b2b.view','b2b.operate','b2b.quotes_manage']),
            'manager'=>$this->roleUser('S4 Manager', ['b2b.view','b2b.view_all','b2b.trade.approve']),
            'reviewer'=>$this->roleUser('S4 Document Reviewer', ['b2b.view','b2b.view_all','b2b.receipts.review']),
            'finance'=>$this->roleUser('S4 Finance', ['accounting.view','finance.b2b_cash_confirm']),
            'refundFinance'=>$this->roleUser('S4 Second Finance', ['accounting.view','finance.b2b_cash_confirm']),
            'other'=>$this->roleUser('S4 Another Seller', ['b2b.view','b2b.operate']),
            'consumer'=>$this->roleUser('S4 Consumer Only', ['customers.view','customers.operate']),
        ];
    }

    /** @return array{order:B2BTradeOrder,tradeNumber:string} */
    private function createTradeViaHttp(User $seller): array
    {
        $org=B2BOrganization::create([
            'display_name'=>'UAT ONLY MY-Wholesale Company',
            'country_code'=>'MY',
            'owner_id'=>$seller->id,
        ]);
        $sales=app(B2BSalesPipeline::class);
        $op=$sales->create([
            'organization_id'=>$org->id,
            'title'=>'UAT ONLY 100 unit wholesale',
            'currency'=>'USD',
            'expected_value_micros'=>2999000000,
        ],$seller->id);
        $quote=$sales->quote($op,[
            'currency'=>'USD','subtotal_micros'=>2999000000,
            'discount_micros'=>0,'tax_micros'=>0,
        ],$seller->id);
        $sales->close($op,'won',$seller->id);

        $this->actingAs($seller)->post('/b2b/trade-orders',[
            'opportunity_id'=>$op->id,'quote_id'=>$quote->id,
        ])->assertRedirect();
        $order=B2BTradeOrder::query()->where('quote_id',$quote->id)->firstOrFail();
        $this->assertSame('draft',$order->status);
        $this->assertSame(2999000000,$order->receivable_micros);
        return ['order'=>$order,'tradeNumber'=>$order->trade_order_number];
    }

    /** @return array<string,mixed> */
    private function claim(string $ref, string $currency='USD', int $micros=2999000000): array
    {
        return [
            'source'=>'bank_transfer',
            'reference'=>$ref,
            'currency'=>$currency,
            'claimed_amount_micros'=>$micros,
            'claimed_paid_on'=>'2026-10-22',
        ];
    }

    /** @return array<string,string> */
    private function statement(string $ref,string $decimal='2999.00'): array
    {
        return [
            'source'=>'bank_transfer',
            'external_reference'=>$ref,
            'amount_decimal'=>$decimal,
            'value_date'=>'2026-10-22',
            'attestation_method'=>'manual_bank_statement',
            'confirmation_note'=>'UAT isolated simulated bank statement cross-checked independently',
        ];
    }

    public function test_full_7_role_http_journey_keeps_trade_receivable_and_finance_truth_separate(): void
    {
        $t=$this->team();
        $initial=$this->createTradeViaHttp($t['seller']);
        $order=$initial['order'];
        $tradeUrl="/b2b/trade-orders/{$order->id}";
        $this->actingAs($t['other'])->get($tradeUrl)->assertNotFound();
        $this->actingAs($t['consumer'])->get($tradeUrl)->assertForbidden();
        $this->actingAs($t['seller'])->post("$tradeUrl/approve")->assertForbidden();
        $this->actingAs($t['manager'])->post("$tradeUrl/approve")->assertRedirect();
        $this->assertSame('approved',$order->fresh()->status);

        $claim=$this->claim('S4-SYNTHETIC-BANK-100');
        $this->actingAs($t['seller'])->post("$tradeUrl/evidence",$claim)->assertRedirect();
        $e=B2BTradeReceiptEvidence::query()->firstOrFail();
        $this->assertSame('pending_review',$e->status);
        // An approved trade and an unreviewed bank document are NOT collected cash.
        $pre=app(B2BTradeCashAccountingService::class)->summary((int)$order->id);
        $this->assertSame('unpaid',$pre['status']);
        $this->assertSame(0,$pre['net_confirmed_micros']);

        $docUrl="$tradeUrl/evidence/{$e->id}/review";
        $this->actingAs($t['seller'])->post($docUrl,[
            'decision'=>'reviewed','reason'=>'Cannot approve own submitted bank proof',
        ])->assertForbidden();
        $this->actingAs($t['reviewer'])->post($docUrl,[
            'decision'=>'reviewed','reason'=>'S4 synthetic paperwork is in valid form',
        ])->assertRedirect();
        $this->assertSame('reviewed',$e->fresh()->status);
        $this->assertSame('unpaid',app(B2BTradeCashAccountingService::class)->summary((int)$order->id)['status']);

        $paymentUrl="/accounting/b2b-trade-cash/{$order->id}/receipts/{$e->id}";
        $this->actingAs($t['manager'])->post($paymentUrl,$this->statement($claim['reference']))
            ->assertForbidden();
        $this->actingAs($t['finance'])->post($paymentUrl,$this->statement($claim['reference']))
            ->assertRedirect();
        $paid=app(B2BTradeCashAccountingService::class)->summary((int)$order->id);
        $this->assertSame('paid',$paid['status']);
        $this->assertSame(2999000000,$paid['net_confirmed_micros']);
        $this->assertSame(0,$paid['outstanding_micros']);

        $financeReceipt=FinanceB2BTradeCashEvent::query()->where('event_type','receipt')->firstOrFail();
        $refundUrl="/accounting/b2b-trade-cash/{$order->id}/refunds/{$financeReceipt->id}";
        $refund=$this->statement('S4-SYNTHETIC-REFUND-001','99.00');
        $this->actingAs($t['finance'])->post($refundUrl,$refund)->assertSessionHasErrors('refund');
        $this->actingAs($t['refundFinance'])->post($refundUrl,$refund)->assertRedirect();
        $net=app(B2BTradeCashAccountingService::class)->summary((int)$order->id);
        $this->assertSame('partial',$net['status']);
        $this->assertSame(2900000000,$net['net_confirmed_micros']);
        $this->assertSame(99000000,$net['outstanding_micros']);

        $this->actingAs($t['seller'])->get($tradeUrl)->assertOk()
            ->assertSee('partial')->assertDontSee($refund['confirmation_note']);
        $this->actingAs($t['finance'])->get("/accounting/b2b-trade-cash/{$order->id}")
            ->assertOk()->assertSee($claim['reference']);
        $this->assertDatabaseCount('b2b_trade_orders',1);
        $this->assertDatabaseCount('b2b_trade_receipt_evidence',1);
        $this->assertDatabaseCount('finance_b2b_trade_cash_events',2);
        $this->assertDatabaseCount('orders',0);
        $this->assertDatabaseCount('order_financials',0);
        $this->assertDatabaseCount('customers',0);
    }

    public function test_failed_finance_requests_do_not_advance_internal_doc_or_generate_cash(): void
    {
        $t=$this->team();
        $order=$this->createTradeViaHttp($t['seller'])['order'];
        $this->actingAs($t['manager'])->post("/b2b/trade-orders/{$order->id}/approve")->assertRedirect();
        $this->actingAs($t['seller'])->post("/b2b/trade-orders/{$order->id}/evidence",$this->claim('S4-DOC-UNREVIEWED'))->assertRedirect();
        $e=B2BTradeReceiptEvidence::firstOrFail();
        $url="/accounting/b2b-trade-cash/{$order->id}/receipts/{$e->id}";
        $this->actingAs($t['finance'])->post($url,$this->statement('S4-DOC-UNREVIEWED'))->assertSessionHasErrors('confirmation');
        $this->assertDatabaseCount('finance_b2b_trade_cash_events',0);
        $this->assertSame('pending_review',$e->fresh()->status);
        $this->assertSame('unpaid',app(B2BTradeCashAccountingService::class)->summary((int)$order->id)['status']);
    }

    public function test_finance_cannot_bind_evidence_or_refunds_to_another_trade_order(): void
    {
        $t=$this->team();
        $a=$this->createTradeViaHttp($t['seller'])['order'];
        $b=$this->createTradeViaHttp($t['seller'])['order'];
        $this->actingAs($t['manager'])->post("/b2b/trade-orders/{$a->id}/approve")->assertRedirect();
        $this->actingAs($t['manager'])->post("/b2b/trade-orders/{$b->id}/approve")->assertRedirect();
        $this->actingAs($t['seller'])->post(
            "/b2b/trade-orders/{$a->id}/evidence",
            $this->claim('S4-OWNER-A-EVIDENCE')
        )->assertRedirect();
        $doc=B2BTradeReceiptEvidence::query()->where('trade_order_id',$a->id)->firstOrFail();
        $this->actingAs($t['reviewer'])->post("/b2b/trade-orders/{$a->id}/evidence/{$doc->id}/review",[
            'decision'=>'reviewed','reason'=>'Verified synthetic reference belongs only to trade A',
        ])->assertRedirect();
        $this->actingAs($t['finance'])->post(
            "/accounting/b2b-trade-cash/{$b->id}/receipts/{$doc->id}",
            $this->statement('S4-OWNER-A-EVIDENCE')
        )->assertNotFound();
        $this->assertDatabaseCount('finance_b2b_trade_cash_events',0);
        $this->actingAs($t['finance'])->post(
            "/accounting/b2b-trade-cash/{$a->id}/receipts/{$doc->id}",
            $this->statement('S4-OWNER-A-EVIDENCE')
        )->assertRedirect();
        $event=FinanceB2BTradeCashEvent::query()->where('trade_order_id',$a->id)->firstOrFail();
        $this->actingAs($t['refundFinance'])->post(
            "/accounting/b2b-trade-cash/{$b->id}/refunds/{$event->id}",
            $this->statement('S4-CROSS-ORDER-REFUND','1.00')
        )->assertNotFound();
        $this->assertSame('unpaid',app(B2BTradeCashAccountingService::class)->summary((int)$b->id)['status']);
        $this->assertDatabaseCount('finance_b2b_trade_cash_events',1);
    }

    public function test_inactive_finance_and_anonymous_browser_requests_cannot_read_or_confirm(): void
    {
        $t=$this->team();
        $order=$this->createTradeViaHttp($t['seller'])['order'];
        $url="/accounting/b2b-trade-cash/{$order->id}";
        auth()->logout();
        $this->get($url)->assertRedirect('/login');
        $this->post("/accounting/b2b-trade-cash/{$order->id}/receipts/999",[
            'source'=>'bank_transfer','external_reference'=>'S4-NOT-A-VALID-RECEIPT',
        ])->assertRedirect('/login');
        $t['finance']->forceFill(['is_active'=>false])->save();
        $this->actingAs($t['finance'])->get($url)->assertRedirect('/login');
        $this->assertDatabaseCount('finance_b2b_trade_cash_events',0);
    }

}
