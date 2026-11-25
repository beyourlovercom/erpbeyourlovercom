<?php

namespace Tests\Feature\Logistics;

use App\Modules\Logistics\Application\LogisticsStatusSummary;
use App\Modules\Logistics\Application\PendingAllocationEntryPolicy;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PendingAllocationEntryPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_woo_statuses_remain_in_erp_but_only_processing_customers_enter_allocation(): void
    {
        $processing = $this->order('woocommerce', 'customer', '2026-11-04');
        $this->financials($processing, 'processing');
        $pending = $this->order('woocommerce', 'customer', '2026-11-04');
        $this->financials($pending, 'pending');
        $completed = $this->order('woocommerce', 'customer', '2026-11-04');
        $this->financials($completed, 'completed');
        $manual = $this->order('manual', 'customer', '2026-11-04');

        $this->assertDatabaseCount('orders', 4);
        $this->assertEqualsCanonicalizing(
            [$processing->id, $manual->id],
            app(LogisticsStatusSummary::class)->pendingAllocationQuery()->pluck('id')->all(),
        );
    }

    public function test_kol_fulfillment_enters_without_approval_and_without_date_cutoff(): void
    {
        $newKol = $this->order('kol', 'kol_fulfillment', '2026-11-04');
        $oldKol = $this->order('manual', 'kol_fulfillment', '2026-10-01');
        $oldWoo = $this->order('woocommerce', 'customer', '2026-10-01');
        $this->financials($oldWoo, 'processing');
        $oldUnpaid = $this->order('woocommerce', 'customer', '2026-10-01');
        $this->financials($oldUnpaid, 'pending');

        $policy = app(PendingAllocationEntryPolicy::class);
        $this->assertTrue($policy->allows($newKol));
        $this->assertTrue($policy->allows($oldKol));
        $this->assertTrue($policy->allows($oldWoo));
        $this->assertFalse($policy->allows($oldUnpaid));
        $this->assertEqualsCanonicalizing(
            [$newKol->id, $oldKol->id, $oldWoo->id],
            app(LogisticsStatusSummary::class)->pendingAllocationQuery()->pluck('id')->all(),
        );
    }

    public function test_already_allocated_kol_fulfillment_is_excluded_from_wait_stock(): void
    {
        $kol = $this->order('manual', 'kol_fulfillment', '2026-10-01');
        $this->assertTrue(app(PendingAllocationEntryPolicy::class)->allows($kol));
        $kol->fulfillmentAllocation()->create(['status'=>'submitted','decision_source'=>'manual']);
        $this->assertFalse(app(LogisticsStatusSummary::class)->pendingAllocationQuery()->whereKey($kol->id)->exists());
    }

    public function test_batch_preview_matches_live_entry_policy_including_force_return(): void
    {
        $paid = $this->order('woocommerce', 'customer', '2026-11-04');
        $this->financials($paid, 'processing');
        $unpaid = $this->order('woocommerce', 'customer', '2026-11-04');
        $this->financials($unpaid, 'pending');
        $forced = $this->order('woocommerce', 'customer', '2026-11-04');
        $this->financials($forced, 'completed');
        $forced->update(['workflow_status' => 'pending_allocation']);
        $kol = $this->order('manual', 'kol_fulfillment', '2026-10-01');
        $manual = $this->order('manual', 'customer', '2026-11-04');
        $orders = collect([$paid, $unpaid, $forced, $kol, $manual]);
        $policy = app(PendingAllocationEntryPolicy::class);
        $this->assertEqualsCanonicalizing(
            $orders->filter(fn (Order $order) => $policy->allows($order))->pluck('id')->all(),
            $policy->eligibleIdsForPreview($orders->pluck('id')->all()),
        );
        $this->assertEqualsCanonicalizing([$paid->id, $forced->id, $kol->id, $manual->id], $policy->eligibleIdsForPreview($orders->pluck('id')->all()));
        $this->assertSame([], $policy->eligibleIdsForPreview([]));
    }

    public function test_collaboration_orders_enter_allocation_without_woo_payment_but_keep_existing_queue_gates(): void
    {
        $partnerIds = \Illuminate\Support\Facades\DB::table('order_collaboration_partners')->pluck('id');
        $this->assertGreaterThanOrEqual(2, $partnerIds->count());
        $first = $this->order('collaboration', 'customer', '2026-11-25');
        $first->update(['collaboration_partner_id' => $partnerIds[0]]);
        $second = $this->order('collaboration', 'customer', '2026-11-25');
        $second->update(['collaboration_partner_id' => $partnerIds[1]]);
        $allocated = $this->order('collaboration', 'customer', '2026-11-25');
        $allocated->fulfillmentAllocation()->create(['status' => 'submitted', 'decision_source' => 'manual']);
        $archived = $this->order('collaboration', 'customer', '2026-11-25');
        $archived->update(['operational_archived_at' => now()]);
        $cancelled = $this->order('collaboration', 'customer', '2026-11-25');
        $cancelled->update(['order_status' => 'cancelled']);

        $policy = app(PendingAllocationEntryPolicy::class);
        $policy->assertAllowed($first);
        $policy->assertAllowed($second);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $policy->eligibleIdsForPreview([$first->id, $second->id]));
        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            app(LogisticsStatusSummary::class)->pendingAllocationQuery()->pluck('id')->all(),
        );
        $this->assertSame(2, app(LogisticsStatusSummary::class)->waitStockCount());
    }

    private function order(string $source, string $kind, string $date): Order
    {
        return Order::query()->create([
            'internal_order_number' => 'ENTRY-'.uniqid(),
            'erp_order_id' => 'ENTRY-'.uniqid(),
            'source_type' => $source,
            'order_kind' => $kind,
            'external_order_number' => 'ENTRY-'.uniqid(),
            'order_date' => $date,
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
    }

    private function financials(Order $order, string $status): void
    {
        $order->financials()->create([
            'currency' => 'USD',
            'provider' => 'woocommerce',
            'provider_reference' => (string) $order->external_order_number,
            'provider_order_status' => $status,
            'provider_modified_at' => now(),
            'payment_method' => 'woocommerce_payments',
            'payment_transaction_id' => $status === 'pending' ? null : 'ENTRY-'.uniqid(),
            'paid_at' => $status === 'pending' ? null : now(),
            'provider_order_total' => '49.00',
            'revenue_provenance' => 'provider_import',
            'completeness_status' => 'INCOMPLETE',
        ]);
    }
}
