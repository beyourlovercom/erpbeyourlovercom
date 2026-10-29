<?php

namespace Tests\Feature\Kol;

use App\Models\User;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class KolHistoricalOrderEvidenceAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_separates_formal_proof_from_sales_attribution_and_never_writes_orders(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $kol = Kol::create([
            'display_name' => 'Synthetic Audit KOL', 'status' => 'active',
            'relationship_stage' => 'cooperating', 'owner_id' => $owner->id,
        ]);

        $missing = $this->order($owner, 'AUDIT-MISSING', null);
        $formal = $this->order($owner, 'AUDIT-FORMAL', null);
        $referral = $this->order($owner, 'AUDIT-REFERRAL', null);
        $explicit = $this->order($owner, 'AUDIT-EXPLICIT', $kol->id);

        DB::table('kol_collaborations')->insert([
            'kol_id' => $kol->id, 'order_id' => $formal->id, 'owner_id' => $owner->id,
            'status' => 'confirmed', 'currency' => 'USD',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('kol_affiliate_referrals')->insert([
            'connection_key' => 'beyourlover.com',
            'referral_id' => 99001, 'affiliate_id' => 200,
            'kol_id' => $kol->id, 'order_id' => $referral->id,
            'order_reference' => 'SALES-200',
            'commission_amount' => 1, 'currency' => 'USD',
            'provider_status' => 'paid', 'context' => 'sale',
            'referral_date' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = Artisan::call('kol:audit-order-links');
        $output = Artisan::output();

        $this->assertSame(0, $result);
        $this->assertStringContainsString('"mode":"READ_ONLY"', $output);
        $this->assertStringContainsString('"auto_match":false', $output);
        $this->assertStringContainsString('"database_updates":0', $output);
        $this->assertStringContainsString('"total":4', $output);
        $this->assertStringContainsString('"explicitly_linked":1', $output);
        $this->assertStringContainsString('"formal_collaboration_candidate":1', $output);
        $this->assertStringContainsString('"referral_review_only":1', $output);
        $this->assertStringContainsString('"missing_evidence":1', $output);

        $this->assertDatabaseHas('orders', ['id' => $missing->id, 'kol_id' => null]);
        $this->assertDatabaseHas('orders', ['id' => $formal->id, 'kol_id' => null]);
        $this->assertDatabaseHas('orders', ['id' => $referral->id, 'kol_id' => null]);
        $this->assertDatabaseHas('orders', ['id' => $explicit->id, 'kol_id' => $kol->id]);
    }

    private function order(User $owner, string $number, ?int $kolId): Order
    {
        return Order::create([
            'internal_order_number' => $number,
            'erp_order_id' => $number,
            'source_type' => 'kol', 'order_kind' => 'kol_fulfillment',
            'external_order_number' => $number,
            'order_date' => '2026-10-29', 'owner_id' => $owner->id,
            'kol_id' => $kolId, 'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
    }
}
