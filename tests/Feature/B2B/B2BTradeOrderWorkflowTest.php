<?php

namespace Tests\Feature\B2B;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\B2B\Infrastructure\Models\B2BOrganization;
use App\Modules\B2B\Infrastructure\Models\B2BSalesOpportunity;
use App\Modules\B2B\Infrastructure\Models\B2BTradeOrder;
use App\Modules\B2B\Sales\B2BSalesPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class B2BTradeOrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $role, array $rights): User
    {
        $r = Role::create(['key' => $role, 'label' => $role]);
        foreach ($rights as $key) {
            $p = Permission::firstOrCreate(['key' => $key], ['label' => $key]);
            $r->permissions()->attach($p);
        }
        $u = User::factory()->create(['is_active' => true]);
        $u->roles()->attach($r);
        return $u;
    }

    private function deal(User $seller, bool $markWon = true): array
    {
        $org = B2BOrganization::create([
            'display_name'=>'UAT ONLY B2B Trade Business',
            'owner_id'=>$seller->id, 'country_code'=>'MY',
        ]);
        $opp = app(B2BSalesPipeline::class)->create([
            'organization_id'=>$org->id,
            'title'=>'UAT Wholesale Trade Opportunity','currency'=>'USD',
            'expected_value_micros'=>250000000,
        ], $seller->id);
        $quote = app(B2BSalesPipeline::class)->quote($opp, [
            'subtotal_micros'=>250000000,
            'discount_micros'=>25000000,
            'tax_micros'=>0,
            'currency'=>'USD',
        ], $seller->id);
        if ($markWon) {
            app(B2BSalesPipeline::class)->close($opp, 'won', $seller->id);
        }
        return [$opp,$quote];
    }

    private function submit(User $seller, B2BSalesOpportunity $opp, int $quoteId): B2BTradeOrder
    {
        $this->actingAs($seller)->post('/b2b/trade-orders', [
            'opportunity_id'=>$opp->id, 'quote_id'=>$quoteId,
        ])->assertRedirect();
        return B2BTradeOrder::query()->firstOrFail();
    }

    public function test_quote_to_trade_draft_snapshots_exact_amount_and_does_not_create_woo_or_revenue(): void
    {
        $seller=$this->actor('s3-g3-seller', ['b2b.view','b2b.operate','b2b.quotes_manage']);
        [$opp,$quote]=$this->deal($seller);
        $row=$this->submit($seller,$opp,$quote->id);
        $this->assertSame('draft',$row->status);
        $this->assertSame('USD',$row->currency);
        $this->assertSame(225000000,$row->receivable_micros);
        $this->assertSame(225000000,$row->quote_snapshot['total_micros']);
        $this->assertSame($opp->organization_id,$row->organization_id);
        $this->assertDatabaseCount('b2b_trade_audit_events',1);
        $this->assertDatabaseCount('orders',0);
        $this->assertDatabaseCount('order_financials',0);
        $this->assertDatabaseCount('customers',0);
        $this->actingAs($seller)->get("/b2b/trade-orders/{$row->id}")->assertOk()->assertSee($row->trade_order_number);
    }

    public function test_same_quote_is_idempotent_and_cross_company_quote_is_blocked(): void
    {
        $seller=$this->actor('s3-g3-idempotent', ['b2b.view','b2b.operate','b2b.quotes_manage']);
        [$opp,$quote]=$this->deal($seller);
        $first=$this->submit($seller,$opp,$quote->id);
        $this->actingAs($seller)->post('/b2b/trade-orders', [
            'opportunity_id'=>$opp->id,'quote_id'=>$quote->id,
        ])->assertRedirect();
        $this->assertDatabaseCount('b2b_trade_orders',1);
        $this->assertSame($first->id, B2BTradeOrder::firstOrFail()->id);
        [$other,] = $this->deal($seller);
        $this->actingAs($seller)->post('/b2b/trade-orders', [
            'opportunity_id'=>$other->id,'quote_id'=>$quote->id,
        ])->assertSessionHasErrors(['quote_id']);
        $this->assertDatabaseCount('b2b_trade_orders',1);
    }

    public function test_unauthorized_customer_and_other_salesperson_cannot_open_or_create(): void
    {
        $seller=$this->actor('s3-g3-owner', ['b2b.view','b2b.operate','b2b.quotes_manage']);
        $other=$this->actor('s3-g3-other', ['b2b.view','b2b.operate']);
        $consumer=$this->actor('s3-g3-consumer', ['customers.view','customers.operate']);
        [$opp,$quote]=$this->deal($seller);
        $row=$this->submit($seller,$opp,$quote->id);
        $this->actingAs($other)->get("/b2b/trade-orders/{$row->id}")->assertNotFound();
        $this->actingAs($other)->post('/b2b/trade-orders', [
            'opportunity_id'=>$opp->id,'quote_id'=>$quote->id,
        ])->assertNotFound();
        $this->actingAs($consumer)->get('/b2b/trade-orders')->assertForbidden();
        $this->actingAs($consumer)->post('/b2b/trade-orders', [
            'opportunity_id'=>$opp->id,'quote_id'=>$quote->id,
        ])->assertForbidden();
    }

    public function test_trade_order_requires_a_second_authorized_approver(): void
    {
        $seller=$this->actor('s3-g3-seller-approval', ['b2b.view','b2b.operate','b2b.quotes_manage','b2b.trade.approve','b2b.view_all']);
        $reviewer=$this->actor('s3-g3-reviewer', ['b2b.view','b2b.view_all','b2b.trade.approve']);
        $operator=$this->actor('s3-g3-operator', ['b2b.view','b2b.view_all']);
        [$opp,$quote]=$this->deal($seller);
        $order=$this->submit($seller,$opp,$quote->id);
        $this->actingAs($seller)->post("/b2b/trade-orders/{$order->id}/approve")->assertSessionHasErrors(['approval']);
        $this->actingAs($operator)->post("/b2b/trade-orders/{$order->id}/approve")->assertForbidden();
        $this->actingAs($reviewer)->post("/b2b/trade-orders/{$order->id}/approve")->assertRedirect();
        $this->assertSame('approved',$order->fresh()->status);
        $this->assertSame($reviewer->id,$order->fresh()->approved_by);
        $this->actingAs($reviewer)->post("/b2b/trade-orders/{$order->id}/approve")->assertSessionHasErrors(['approval']);
        $this->assertDatabaseCount('order_financials',0);
        $this->assertDatabaseCount('b2b_trade_audit_events',2);
    }

    public function test_payment_evidence_needs_approval_unique_reference_and_separate_reviewer(): void
    {
        $seller=$this->actor('s3-g3-evidence-seller', ['b2b.view','b2b.operate','b2b.quotes_manage']);
        $approver=$this->actor('s3-g3-approver', ['b2b.view','b2b.view_all','b2b.trade.approve','b2b.receipts.review']);
        $reviewer=$this->actor('s3-g3-independent-reviewer', ['b2b.view','b2b.view_all','b2b.receipts.review']);
        [$opp,$quote]=$this->deal($seller);
        $order=$this->submit($seller,$opp,$quote->id);
        $input=['source'=>'bank_transfer','reference'=>'UAT-BANK-TRANSFER-001','currency'=>'USD',
            'claimed_amount_micros'=>100000000];
        $this->actingAs($seller)->post("/b2b/trade-orders/{$order->id}/evidence", $input)
            ->assertSessionHasErrors(['trade_order']);
        $this->actingAs($approver)->post("/b2b/trade-orders/{$order->id}/approve")->assertRedirect();
        $this->actingAs($seller)->post("/b2b/trade-orders/{$order->id}/evidence", $input)->assertRedirect();
        $this->actingAs($seller)->post("/b2b/trade-orders/{$order->id}/evidence", $input)
            ->assertSessionHasErrors(['reference']);
        $this->assertDatabaseCount('b2b_trade_receipt_evidence',1);
        $e=$order->evidence()->firstOrFail();
        $this->assertSame('pending_review',$e->status);
        $this->actingAs($seller)->post("/b2b/trade-orders/{$order->id}/evidence/{$e->id}/review", [
            'decision'=>'reviewed','reason'=>'Self review is forbidden',
        ])->assertForbidden();
        $this->actingAs($reviewer)->post("/b2b/trade-orders/{$order->id}/evidence/{$e->id}/review", [
            'decision'=>'reviewed','reason'=>'Checked attached information; no settlement confirmed',
        ])->assertRedirect();
        $this->assertSame('reviewed',$e->fresh()->status);
        $this->assertDatabaseCount('order_financials',0);
        $this->assertDatabaseCount('orders',0);
    }

    public function test_evidence_reference_collision_and_currency_mismatch_fail_closed(): void
    {
        $seller=$this->actor('s3-g3-safe-seller', ['b2b.view','b2b.operate','b2b.quotes_manage']);
        $approver=$this->actor('s3-g3-safe-approver', ['b2b.view','b2b.view_all','b2b.trade.approve']);
        [$opp,$quote]=$this->deal($seller);
        $order=$this->submit($seller,$opp,$quote->id);
        $this->actingAs($approver)->post("/b2b/trade-orders/{$order->id}/approve")->assertRedirect();
        $this->actingAs($seller)->post("/b2b/trade-orders/{$order->id}/evidence", [
            'source'=>'bank_transfer','reference'=>'UAT-CURRENCY','currency'=>'EUR','claimed_amount_micros'=>10000000,
        ])->assertSessionHasErrors(['reference']);
        $this->assertDatabaseCount('b2b_trade_receipt_evidence',0);
        $this->assertSame('approved',$order->fresh()->status);
    }

    public function test_internal_order_approval_requires_won_opportunity_not_merely_draft_quote(): void
    {
        $seller=$this->actor('s3-g3-prewon-seller', ['b2b.view','b2b.operate','b2b.quotes_manage']);
        $approver=$this->actor('s3-g3-prewon-reviewer', ['b2b.view','b2b.view_all','b2b.trade.approve']);
        [$opp,$quote]=$this->deal($seller, false);
        $order=$this->submit($seller,$opp,$quote->id);
        $this->actingAs($approver)->post("/b2b/trade-orders/{$order->id}/approve")->assertSessionHasErrors(['approval']);
        $this->assertSame('draft', $order->fresh()->status);
        $this->assertDatabaseCount('order_financials',0);
        app(B2BSalesPipeline::class)->close($opp,'won',$seller->id);
        $this->actingAs($approver)->post("/b2b/trade-orders/{$order->id}/approve")->assertRedirect();
        $this->assertSame('approved', $order->fresh()->status);
    }

}
