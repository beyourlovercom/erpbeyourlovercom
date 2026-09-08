<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\CostMaterializationService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Application\ConfirmedProductCostSourceImportService;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductCostSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ConfirmedProductCostSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_plan_is_dry_run_and_same_cost_variants_can_be_applied_idempotently(): void
    {
        $product = $this->product('READY-1');
        $rows = [
            $this->row($product, 'LONG-A', '117.600000000000', 'CNY'),
            $this->row($product, 'LONG-B', '117.6', 'CNY'),
        ];
        $service = $this->app->make(ConfirmedProductCostSourceImportService::class);
        $plan = $service->plan($rows, '成本货本007组 7月进销存.xlsx', '2026-07-01', '2026-07-31');
        self::assertSame(2, $plan['create']);
        self::assertSame(0, $plan['blocked']);
        self::assertSame(0, ProductCostSource::query()->count());

        $applied = $service->apply($plan);
        self::assertSame(2, $applied['created']);
        self::assertSame(2, ProductCostSource::query()->count());

        $again = $service->plan($rows, '成本货本007组 7月进销存.xlsx', '2026-07-01', '2026-07-31');
        self::assertSame(0, $again['create']);
        self::assertSame(2, $again['noop']);
        self::assertSame(0, $again['blocked']);
    }

    public function test_different_cost_variants_for_one_product_period_fail_closed(): void
    {
        $product = $this->product('AMB-1');
        $service = $this->app->make(ConfirmedProductCostSourceImportService::class);
        $plan = $service->plan([
            $this->row($product, 'LONG-A', '10.00', 'CNY'),
            $this->row($product, 'LONG-B', '11.00', 'CNY'),
        ], 'source.xlsx', '2026-07-01');

        self::assertSame(2, $plan['blocked']);
        self::assertSame('multiple_confirmed_costs_for_product_period', $plan['rows'][0]['blocker']);
        self::assertSame('multiple_confirmed_costs_for_product_period', $plan['rows'][1]['blocker']);
        self::assertSame(0, ProductCostSource::query()->count());
    }

    public function test_same_currency_confirmed_source_materializes_confirmed_and_is_noop_on_repeat(): void
    {
        $product = $this->product('CNY-READY');
        $this->source($product, 'LONG-1', '12.345000000000', 'CNY');
        $order = $this->order('CNY', '2026-07-15');
        $order->items()->create(['product_id' => $product->id, 'product_summary' => 'x', 'sku' => $product->sku, 'quantity' => 2, 'unit_price' => '50.00', 'line_revenue' => '100.00']);

        $service = $this->app->make(CostMaterializationService::class);
        $first = $service->apply($order);
        $entry = $order->costEntries()->where('cost_type', 'product_cost')->firstOrFail();
        self::assertSame('24.69', $entry->amount);
        self::assertSame('CNY', $entry->currency);
        self::assertSame('confirmed_manual', $entry->provenance);
        self::assertSame('confirmed', $entry->status);
        self::assertCount(1, array_filter($first['applied'], fn (array $row): bool => ($row['cost_type'] ?? null) === 'product_cost'));

        $second = $service->apply($order->fresh());
        self::assertCount(1, array_filter($second['noop'], fn (array $row): bool => ($row['cost_type'] ?? null) === 'product_cost'));
    }

    public function test_cny_confirmed_source_on_usd_order_is_blocked_until_fx_bridge_exists(): void
    {
        $product = $this->product('USD-BLOCK');
        $this->source($product, 'LONG-1', '117.600000000000', 'CNY');
        $order = $this->order('USD', '2026-07-15');
        $order->items()->create(['product_id' => $product->id, 'product_summary' => 'x', 'sku' => $product->sku, 'quantity' => 1, 'unit_price' => '50.00', 'line_revenue' => '50.00']);

        $candidate = $this->candidate($this->app->make(CostMaterializationService::class)->preview($order), 'product_cost');
        self::assertSame('blocked', $candidate['materialization_status']);
        self::assertSame('confirmed_product_cost_fx_conversion_required', $candidate['blocker']);
        self::assertSame('CNY', $candidate['source_currency']);
        self::assertSame('USD', $candidate['order_currency']);
        self::assertSame('117.60', $candidate['source_amount']);
        self::assertFalse($order->costEntries()->where('cost_type', 'product_cost')->exists());
    }

    public function test_latest_effective_confirmed_cost_version_wins_for_later_orders(): void
    {
        $product = $this->product('VERSION-1');
        $this->source($product, 'JULY-LONG', '10.000000000000', 'CNY', '2026-07-01');
        $this->source($product, 'AUG-LONG', '12.000000000000', 'CNY', '2026-08-01');
        $order = $this->order('CNY', '2026-08-15');
        $order->items()->create(['product_id' => $product->id, 'product_summary' => 'x', 'sku' => $product->sku, 'quantity' => 2, 'unit_price' => '20.00', 'line_revenue' => '40.00']);

        $candidate = $this->candidate($this->app->make(CostMaterializationService::class)->preview($order), 'product_cost');
        self::assertSame('24.00', $candidate['amount']);
        self::assertSame('confirmed', $candidate['status']);
        self::assertSame(['AUG-LONG'], $candidate['source_keys']);
    }

    public function test_import_blocks_conflict_with_existing_confirmed_source_in_same_period(): void
    {
        $product = $this->product('PERIOD-CONFLICT');
        $this->source($product, 'LONG-OLD', '10.000000000000', 'CNY', '2026-07-01');
        $service = $this->app->make(ConfirmedProductCostSourceImportService::class);
        $plan = $service->plan([$this->row($product, 'LONG-NEW', '11.00', 'CNY')], 'new-source.xlsx', '2026-07-01');

        self::assertSame(1, $plan['blocked']);
        self::assertSame('existing_period_cost_conflict', $plan['rows'][0]['blocker']);
    }

    public function test_source_effective_date_is_respected_and_standard_reference_fallback_is_unchanged(): void
    {
        $product = $this->product('DATE-1', ['standard_purchase_cost' => '3.0000', 'cost_currency' => 'USD']);
        $this->source($product, 'LONG-1', '20.000000000000', 'CNY', '2026-07-01');
        $order = $this->order('USD', '2026-06-30');
        $order->items()->create(['product_id' => $product->id, 'product_summary' => 'x', 'sku' => $product->sku, 'quantity' => 2, 'unit_price' => '10.00', 'line_revenue' => '20.00']);

        $candidate = $this->candidate($this->app->make(CostMaterializationService::class)->preview($order), 'product_cost');
        self::assertSame('estimated_reference', $candidate['provenance']);
        self::assertSame('estimated', $candidate['status']);
        self::assertSame('6.00', $candidate['amount']);
    }

    private function product(string $sku, array $extra = []): Product
    {
        return Product::query()->create(['source_type' => 'manual', 'product_type' => 'simple', 'sku' => $sku, 'name' => $sku, ...$extra]);
    }

    private function row(Product $product, string $key, string $cost, string $currency): array
    {
        return ['erp_product_id' => (string) $product->id, 'erp_sku' => $product->sku, 'source_variant_key' => $key, 'unit_cost' => $cost, 'currency' => $currency];
    }

    private function source(Product $product, string $key, string $cost, string $currency, string $effective = '2026-07-01'): ProductCostSource
    {
        return ProductCostSource::query()->create([
            'product_id' => $product->id, 'source_key' => $key, 'unit_cost' => $cost, 'currency' => $currency,
            'provenance' => 'confirmed_source_file', 'status' => 'confirmed', 'source_type' => 'inventory_cost_book',
            'source_reference' => 'source.xlsx', 'effective_from' => $effective, 'source_at' => '2026-07-31 00:00:00',
            'confirmed_at' => '2026-09-08 00:00:00', 'source_fingerprint' => hash('sha256', $product->id.'|'.$key.'|'.$effective),
        ]);
    }

    private function order(string $currency, string $date): Order
    {
        return Order::query()->create([
            'internal_order_number' => 'PCS-'.strtoupper(uniqid()), 'source_type' => 'manual', 'order_kind' => 'customer',
            'order_date' => $date, 'order_status' => 'pending_shipment', 'currency' => $currency,
        ]);
    }

    private function candidate(array $payload, string $type): array
    {
        foreach ($payload['candidates'] as $candidate) {
            if (($candidate['cost_type'] ?? null) === $type) {
                return $candidate;
            }
        }
        self::fail('Candidate not found: '.$type);
    }
}
