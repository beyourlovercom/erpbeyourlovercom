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

        $policy = app(PendingAllocationEntryPolicy::class);
        $this->assertTrue($policy->allows($newKol));
        $this->assertTrue($policy->allows($oldKol));
        $this->assertFalse($policy->allows($oldWoo));
        $this->assertEqualsCanonicalizing(
            [$newKol->id, $oldKol->id],
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
