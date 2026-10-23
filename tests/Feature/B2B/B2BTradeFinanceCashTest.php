<?php

namespace Tests\Feature\B2B;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\B2B\Infrastructure\Models\B2BOrganization;
use App\Modules\B2B\Infrastructure\Models\B2BTradeOrder;
use App\Modules\B2B\Sales\B2BSalesPipeline;
use App\Modules\B2B\Sales\B2BTradeOrderService;
use App\Modules\Finance\Application\B2BTradeCashAccountingService;
use App\Modules\Finance\Infrastructure\Models\FinanceB2BTradeCashEvent;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Finance\Infrastructure\Models\OrderFinancial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class B2BTradeFinanceCashTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $key, array $permissions): User
    {
        $r=Role::create(['key'=>$key,'label'=>$key]);
        foreach ($permissions as $right) {
            $p=Permission::firstOrCreate(['key'=>$right],['label'=>$right]);
            $r->permissions()->attach($p);
        }
        $user=User::factory()->create(['is_active'=>true]);
        $user->roles()->attach($r);
        return $user;
    }

    private function setupTrade(): array
    {
        $seller=$this->actor('g4-seller',['b2b.view','b2b.operate','b2b.quotes_manage']);
        $approver=$this->actor('g4-approver',['b2b.view','b2b.view_all','b2b.trade.approve']);
        $reviewer=$this->actor('g4-document-reviewer',['b2b.view','b2b.view_all','b2b.receipts.review']);
        $finance=$this->actor('g4-finance',['accounting.view','finance.b2b_cash_confirm']);
        $refundVerifier=$this->actor('g4-refund-finance',['accounting.view','finance.b2b_cash_confirm']);
        $org=B2BOrganization::create([
            'display_name'=>'UAT Sample Only Finance Distributor', 'owner_id'=>$seller->id,'country_code'=>'MY',
        ]);
        $opp=app(B2BSalesPipeline::class)->create([
            'organization_id'=>$org->id,'title'=>'UAT Only Trade Payment',
            'currency'=>'USD','expected_value_micros'=>250000000,
        ],$seller->id);
        $quote=app(B2BSalesPipeline::class)->quote($opp,[
            'subtotal_micros'=>250000000,'discount_micros'=>0,'tax_micros'=>0,'currency'=>'USD',
        ],$seller->id);
        app(B2BSalesPipeline::class)->close($opp,'won',$seller->id);
        $order=app(B2BTradeOrderService::class)->draft($opp,$quote,$seller->id);
        app(B2BTradeOrderService::class)->approve($order,$approver->id);
        $evidence=app(B2BTradeOrderService::class)->recordEvidence($order,[
            'source'=>'bank_transfer','reference'=>'UAT-G4-BANK-001','currency'=>'USD',
            'claimed_amount_micros'=>100000000,'claimed_paid_on'=>'2026-10-21',
        ],$seller->id);
        app(B2BTradeOrderService::class)->reviewEvidence(
            $evidence,$reviewer->id,'reviewed','UAT synthetic bank evidence document reviewed'
        );
        return compact('seller','approver','reviewer','finance','refundVerifier','order','evidence');
    }

    private function receiptRequest(): array
    {
        return [
            'source'=>'bank_transfer',
            'external_reference'=>'UAT-G4-BANK-001',
            'amount_decimal'=>'100.00',
            'value_date'=>'2026-10-21',
            'attestation_method'=>'manual_bank_statement',
            'confirmation_note'=>'UAT simulated external statement independently checked',
        ];
    }

    private function refundRequest(string $ref='UAT-G4-REFUND-001', string $amount='25.00'): array
    {
        return [
            'source'=>'bank_transfer','external_reference'=>$ref,
            'amount_decimal'=>$amount,'value_date'=>'2026-10-22',
            'attestation_method'=>'manual_bank_statement',
            'confirmation_note'=>'UAT simulated independent bank refund statement',
        ];
    }

    public function test_reviewed_sales_document_is_not_actual_finance_cash_until_treasury_confirmation(): void
    {
        $x=$this->setupTrade();
        $summary=app(B2BTradeCashAccountingService::class)->summary((int)$x['order']->id);
        $this->assertSame('unpaid',$summary['status']);
        $this->assertSame(0,$summary['net_confirmed_micros']);
        $this->assertSame(250000000,$summary['outstanding_micros']);
        $this->assertDatabaseCount('finance_b2b_trade_cash_events',0);
        $this->assertDatabaseCount('order_financials',0);
        $this->assertDatabaseCount('orders',0);
    }

    public function test_only_finance_can_confirm_and_finance_actor_must_be_independent(): void
    {
        $x=$this->setupTrade();
        $path="/accounting/b2b-trade-cash/{$x['order']->id}/receipts/{$x['evidence']->id}";
        $this->actingAs($x['seller'])->post($path,$this->receiptRequest())->assertForbidden();
        $this->actingAs($x['reviewer'])->post($path,$this->receiptRequest())->assertForbidden();
        $this->actingAs($x['finance'])->post($path,$this->receiptRequest())->assertRedirect();
        $this->assertDatabaseCount('finance_b2b_trade_cash_events',1);
        $summary=app(B2BTradeCashAccountingService::class)->summary((int)$x['order']->id);
        $this->assertSame('partial',$summary['status']);
        $this->assertSame(100000000,$summary['net_confirmed_micros']);
        $this->assertSame(150000000,$summary['outstanding_micros']);
        $this->assertDatabaseCount('order_financials',0);
    }

    public function test_duplicate_external_reference_or_same_evidence_cannot_post_twice(): void
    {
        $x=$this->setupTrade();
        $url="/accounting/b2b-trade-cash/{$x['order']->id}/receipts/{$x['evidence']->id}";
        $this->actingAs($x['finance'])->post($url,$this->receiptRequest())->assertRedirect();
        $this->actingAs($x['finance'])->post($url,$this->receiptRequest())
            ->assertSessionHasErrors(['external_reference']);
        $this->assertDatabaseCount('finance_b2b_trade_cash_events',1);
    }

    public function test_finance_confirmation_requires_exact_amount_reference_and_source(): void
    {
        $x=$this->setupTrade();
        $url="/accounting/b2b-trade-cash/{$x['order']->id}/receipts/{$x['evidence']->id}";
        foreach ([
            ['amount_decimal'=>'101.00'],
            ['external_reference'=>'UAT-DIFFERENT-REF'],
            ['source'=>'provider_reference','attestation_method'=>'manual_provider_statement'],
            ['confirmation_note'=>'short'],
        ] as $override) {
            $this->actingAs($x['finance'])->post($url,array_merge($this->receiptRequest(),$override))
                ->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('finance_b2b_trade_cash_events',0);
    }

    public function test_refund_needs_a_second_finance_person_and_cannot_exceed_confirmed_receipt(): void
    {
        $x=$this->setupTrade();
        $url="/accounting/b2b-trade-cash/{$x['order']->id}/receipts/{$x['evidence']->id}";
        $this->actingAs($x['finance'])->post($url,$this->receiptRequest())->assertRedirect();
        $receipt=FinanceB2BTradeCashEvent::firstOrFail();
        $refundUrl="/accounting/b2b-trade-cash/{$x['order']->id}/refunds/{$receipt->id}";
        $this->actingAs($x['finance'])->post($refundUrl,$this->refundRequest())
            ->assertSessionHasErrors(['refund']);
        $this->actingAs($x['refundVerifier'])->post($refundUrl,$this->refundRequest())->assertRedirect();
        $this->assertSame('partial',app(B2BTradeCashAccountingService::class)->summary((int)$x['order']->id)['status']);
        $this->assertSame(75000000,app(B2BTradeCashAccountingService::class)->summary((int)$x['order']->id)['net_confirmed_micros']);
        $this->actingAs($x['refundVerifier'])->post($refundUrl,$this->refundRequest('UAT-G4-REFUND-TOO-MUCH','90.00'))
            ->assertSessionHasErrors(['refund']);
        $this->assertDatabaseCount('finance_b2b_trade_cash_events',2);
    }

    public function test_c_end_role_cannot_view_finance_b2b_data_even_with_customer_permissions(): void
    {
        $x=$this->setupTrade();
        $consumer=$this->actor('g4-cend-user',['customers.view','customers.operate']);
        $this->actingAs($consumer)->get('/accounting/b2b-trade-cash')->assertForbidden();
        $this->actingAs($consumer)->get("/accounting/b2b-trade-cash/{$x['order']->id}")->assertForbidden();
        $this->actingAs($x['finance'])->get('/accounting/b2b-trade-cash')->assertOk()
            ->assertSee($x['order']->trade_order_number);
        $this->actingAs($x['finance'])->get("/accounting/b2b-trade-cash/{$x['order']->id}")->assertOk()
            ->assertSee('UAT-G4-BANK-001');
    }

    public function test_business_side_trade_only_reads_finance_confirmed_summary_not_internal_statement(): void
    {
        $x=$this->setupTrade();
        $this->actingAs($x['finance'])->post(
            "/accounting/b2b-trade-cash/{$x['order']->id}/receipts/{$x['evidence']->id}",
            $this->receiptRequest()
        )->assertRedirect();
        $this->actingAs($x['seller'])->get("/b2b/trade-orders/{$x['order']->id}")
            ->assertOk()->assertSee('partial')->assertSee('100000000')
            ->assertDontSee('UAT simulated external statement independently checked');
    }

    public function test_provider_reference_already_used_by_woo_financials_must_be_rejected(): void
    {
        $x=$this->setupTrade();
        // Synthetic consumer payment already claimed by the canonical Woo financial model.
        $consumerOrder=Order::query()->create([
            'internal_order_number'=>'UAT-G4-WOO-001','source_type'=>'manual',
            'order_kind'=>'customer','order_date'=>'2026-10-22',
            'order_status'=>'pending_shipment','currency'=>'USD',
        ]);
        $consumerOrder->financials()->create([
            'currency'=>'USD',
            'amount_paid'=>'100.00',
            'payment_transaction_id'=>'UAT-G4-BANK-001',
        ]);
        // Even a matching business receipt document and authorized Finance user
        // cannot claim a single provider transaction in the independent Trade ledger.
        $url="/accounting/b2b-trade-cash/{$x['order']->id}/receipts/{$x['evidence']->id}";
        $request=array_merge($this->receiptRequest(),[
            'source'=>'provider_reference','attestation_method'=>'manual_provider_statement',
        ]);
        // A bank-only claim cannot be promoted to provider payment source anyway.
        $this->actingAs($x['finance'])->post($url,$request)->assertSessionHasErrors(['confirmation']);
        // A separate provider claim with the same reference still cannot be double booked.
        $providerEvidence=app(B2BTradeOrderService::class)->recordEvidence($x['order'],[
            'source'=>'provider_reference','reference'=>'UAT-G4-BANK-001','currency'=>'USD',
            'claimed_amount_micros'=>100000000,'claimed_paid_on'=>'2026-10-21',
        ],$x['seller']->id);
        app(B2BTradeOrderService::class)->reviewEvidence(
            $providerEvidence,$x['reviewer']->id,'reviewed','UAT synthetic provider document inspected'
        );
        $this->actingAs($x['finance'])->post(
            "/accounting/b2b-trade-cash/{$x['order']->id}/receipts/{$providerEvidence->id}",
            $request
        )->assertSessionHasErrors(['external_reference']);
        $this->assertDatabaseCount('finance_b2b_trade_cash_events',0);
        $this->assertDatabaseCount('order_financials',1);
    }


    public function test_confirmed_finance_events_are_append_only_in_eloquent(): void
    {
        $x=$this->setupTrade();
        $this->actingAs($x['finance'])->post(
            "/accounting/b2b-trade-cash/{$x['order']->id}/receipts/{$x['evidence']->id}",
            $this->receiptRequest()
        )->assertRedirect();
        $event=FinanceB2BTradeCashEvent::firstOrFail();
        try {
            $event->forceFill(['confirmation_note'=>'attempted overwrite'])->save();
            $this->fail('Finance cash event was changed in place');
        } catch (\LogicException $expected) {
            $this->assertStringContainsString('append-only',$expected->getMessage());
        }
        $event->refresh();
        $this->assertStringNotContainsString('attempted overwrite',$event->confirmation_note);
        try {
            $event->delete();
            $this->fail('Finance cash event was deleted');
        } catch (\LogicException $expected) {
            $this->assertStringContainsString('cannot be deleted',$expected->getMessage());
        }
        $this->assertDatabaseCount('finance_b2b_trade_cash_events',1);
    }


    public function test_typed_finance_gateway_returns_persisted_event_identity_and_rejects_duplicate_direct_call(): void
    {
        $x=$this->setupTrade();
        $cash=app(B2BTradeCashAccountingService::class);
        $attestation=[...$this->receiptRequest(),'amount_micros'=>100000000];
        $createdId=$cash->confirmReceipt((int)$x['order']->id,(int)$x['evidence']->id,$attestation,(int)$x['finance']->id);
        $this->assertSame((int)FinanceB2BTradeCashEvent::query()->firstOrFail()->getKey(),$createdId);
        try {
            $cash->confirmReceipt((int)$x['order']->id,(int)$x['evidence']->id,$attestation,(int)$x['finance']->id);
            $this->fail('A confirmed receipt reference may never be counted twice.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('external_reference',$exception->errors());
        }
        $this->assertDatabaseCount('finance_b2b_trade_cash_events',1);
        $this->assertDatabaseCount('order_financials',0);
    }

    public function test_typed_finance_gateway_rejects_inactive_finance_even_without_http_middleware(): void
    {
        $x=$this->setupTrade();
        $x['finance']->update(['is_active'=>false]);
        $cash=app(B2BTradeCashAccountingService::class);
        try {
            $cash->confirmReceipt((int)$x['order']->id,(int)$x['evidence']->id,
                [...$this->receiptRequest(),'amount_micros'=>100000000],(int)$x['finance']->id);
            $this->fail('Inactive Finance must fail closed at service boundary.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403,$exception->getStatusCode());
        }
        $this->assertDatabaseCount('finance_b2b_trade_cash_events',0);
        $this->assertDatabaseCount('order_financials',0);
    }

    public function test_typed_finance_gateway_refund_requires_separate_finance_and_returns_refund_id(): void
    {
        $x=$this->setupTrade();
        $cash=app(B2BTradeCashAccountingService::class);
        $receiptId=$cash->confirmReceipt((int)$x['order']->id,(int)$x['evidence']->id,
            [...$this->receiptRequest(),'amount_micros'=>100000000],(int)$x['finance']->id);
        $refund=[...$this->refundRequest('UAT-DIRECT-REFUND-002','25.00'),'amount_micros'=>25000000];
        try {
            $cash->confirmRefund((int)$x['order']->id,$receiptId,$refund,(int)$x['finance']->id);
            $this->fail('The receipt verifier may not verify their own refund.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertArrayHasKey('refund',$exception->errors());
        }
        $this->assertDatabaseCount('finance_b2b_trade_cash_events',1);
        $refundId=$cash->confirmRefund((int)$x['order']->id,$receiptId,$refund,(int)$x['refundVerifier']->id);
        $record=FinanceB2BTradeCashEvent::query()->findOrFail($refundId);
        $this->assertSame('refund',$record->event_type);
        $this->assertSame($receiptId,(int)$record->refund_of_id);
        $this->assertSame(75000000,$cash->summary((int)$x['order']->id)['net_confirmed_micros']);
        $this->assertDatabaseCount('order_financials',0);
    }
}
