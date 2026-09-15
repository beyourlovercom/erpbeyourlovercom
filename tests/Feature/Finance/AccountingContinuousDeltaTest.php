<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\AccountingContinuousDeltaService;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductCostSource;
use App\Modules\Sync\Application\Contracts\SyncRunTracker;
use App\Modules\Sync\Infrastructure\Models\SyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AccountingContinuousDeltaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10T12:00:00Z'));
        config(['erp_accounting.continuous_delta.enabled' => false]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_dry_run_uses_initial_lookback_and_writes_nothing(): void
    {
        $order = $this->readyOrder();
        $this->stampOrder($order, '2026-09-10 10:30:00');

        $summary = $this->service()->preview(100, CarbonImmutable::parse('2026-09-10T12:00:00Z'));

        self::assertSame('dry_run', $summary['mode']);
        self::assertSame('2026-09-10T09:00:00+00:00', $summary['window_start']);
        self::assertSame('2026-09-10T12:00:00+00:00', $summary['window_end']);
        self::assertSame(1, $summary['candidate_count']);
        self::assertArrayHasKey('last_mile_estimate', $summary['stages']);
        self::assertArrayHasKey('last_mile_actual', $summary['stages']);
        self::assertSame(0, SyncRun::query()->count());
        self::assertSame(0, $order->costEntries()->count());
    }

    public function test_apply_persists_checkpoint_and_idempotent_overlap_rerun(): void
    {
        $order = $this->readyOrder();
        $first = $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T12:00:00Z'));

        self::assertTrue($first['checkpoint_advanced']);
        self::assertSame('2026-09-10T12:00:00+00:00', $first['checkpoint_after']);
        self::assertSame(0, $first['failures']);
        self::assertSame(4, $order->costEntries()->count());
        self::assertSame('3.50', $order->costEntries()->where('cost_type', 'payment_fee')->firstOrFail()->amount);

        $second = $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T12:01:00Z'));
        self::assertSame('2026-09-10T11:55:00+00:00', $second['window_start']);
        self::assertSame(4, $order->costEntries()->count());
        self::assertSame(0, $second['stages']['payment_fee']['applied']);
        self::assertGreaterThanOrEqual(1, $second['stages']['payment_fee']['noop']);
    }

    public function test_five_minute_overlap_recovers_late_change_before_checkpoint(): void
    {
        $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T12:00:00Z'));
        $late = $this->readyOrder();
        $this->stampOrderAndRelations($late, '2026-09-10 11:57:00');

        $summary = $this->service()->preview(100, CarbonImmutable::parse('2026-09-10T13:00:00Z'));

        self::assertSame('2026-09-10T11:55:00+00:00', $summary['window_start']);
        self::assertSame(1, $summary['candidate_count']);
    }

    public function test_hard_cap_fails_before_any_materialization_or_sync_run_write(): void
    {
        $one = $this->readyOrder();
        $two = $this->readyOrder();

        $summary = $this->service()->apply(1, CarbonImmutable::parse('2026-09-10T12:00:00Z'));

        self::assertSame('blocked_candidate_limit_exceeded', $summary['gate']);
        self::assertTrue($summary['candidate_overflow']);
        self::assertSame(2, $summary['candidate_count']);
        self::assertSame(0, SyncRun::query()->count());
        self::assertSame(0, $one->costEntries()->count());
        self::assertSame(0, $two->costEntries()->count());
    }

    public function test_business_blockers_are_nonfatal_and_checkpoint_advances(): void
    {
        $order = $this->bareOrder();

        $summary = $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T12:00:00Z'));

        self::assertSame(0, $summary['failures']);
        self::assertTrue($summary['checkpoint_advanced']);
        self::assertGreaterThan(0, $summary['stages']['product_cost']['blocked']);
        self::assertGreaterThan(0, $summary['stages']['payment_fee']['blocked']);
        self::assertSame('0.00', $order->costEntries()->where('cost_type', 'customs_tax')->firstOrFail()->amount);
    }

    public function test_runtime_failure_does_not_advance_checkpoint(): void
    {
        $this->readyOrder();
        Schema::drop('order_cost_entries');

        $summary = $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T12:00:00Z'));

        self::assertSame(1, $summary['failures']);
        self::assertFalse($summary['checkpoint_advanced']);
        self::assertNull($summary['checkpoint_after']);
        self::assertNull(app(SyncRunTracker::class)->latestContextString(
            'accounting_continuous_delta', 'internal', 'finance', 'checkpoint_at'
        ));
    }

    public function test_shipment_change_reenters_scope_after_checkpoint(): void
    {
        $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T12:00:00Z'));
        $order = $this->readyOrder();
        $this->stampOrderAndRelations($order, '2026-09-10 10:00:00');
        DB::table('shipments')->where('order_id', $order->id)->update([
            'updated_at' => '2026-09-10 12:30:00',
        ]);

        $summary = $this->service()->preview(100, CarbonImmutable::parse('2026-09-10T13:00:00Z'));

        self::assertSame(1, $summary['candidate_count']);
    }

    public function test_carrier_billing_change_reenters_scope_and_materializes_actual_last_mile(): void
    {
        $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T12:00:00Z'));
        $order = $this->readyOrder();
        $this->stampOrderAndRelations($order, '2026-09-10 10:00:00');
        DB::table('orders')->where('id', $order->id)->update([
            'order_status' => 'shipped',
            'updated_at' => '2026-09-10 10:00:00',
        ]);
        DB::table('shipments')->where('order_id', $order->id)->update([
            'shipment_status' => 'shipped',
            'tracking_number' => 'P6C-BILL-'.$order->id,
            'updated_at' => '2026-09-10 10:00:00',
        ]);
        $shipment = $order->shipment()->firstOrFail();
        $order->costEntries()->create([
            'cost_type' => 'last_mile_cost', 'amount' => '9.99', 'currency' => 'USD',
            'provenance' => 'estimated_reference', 'status' => 'estimated',
            'source_reference' => 'last-mile-estimate:v1:order:'.$order->id,
        ]);
        $billing = $shipment->billingEvidence()->create([
            'actual_cost' => '12.3400', 'currency' => 'USD',
            'billing_document_number' => 'P6C-BILL-'.$order->id,
            'data_source' => 'carrier_bill_import:p6c-test',
            'billed_at' => '2026-09-10 12:30:00',
        ]);
        DB::table('shipment_carrier_billing')->where('id', $billing->id)->update([
            'created_at' => '2026-09-10 12:30:00',
            'updated_at' => '2026-09-10 12:30:00',
        ]);

        $preview = $this->service()->preview(100, CarbonImmutable::parse('2026-09-10T13:00:00Z'));
        self::assertSame(1, $preview['candidate_count']);
        self::assertSame(1, $preview['stages']['last_mile_actual']['applied']);
        self::assertSame(1, $preview['stages']['last_mile_estimate']['blocked']);

        $applied = $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T13:00:00Z'));
        self::assertSame(1, $applied['stages']['last_mile_actual']['applied']);
        $entry = $order->costEntries()->where('cost_type', 'last_mile_cost')->firstOrFail()->fresh();
        self::assertSame('12.34', $entry->amount);
        self::assertSame('provider_import', $entry->provenance);
        self::assertSame('confirmed', $entry->status);
    }

    public function test_strong_facts_are_protected_and_p6_through_p9_cost_types_are_untouched(): void
    {
        $order = $this->readyOrder();
        $order->costEntries()->create([
            'cost_type' => 'product_cost', 'amount' => '99.00', 'currency' => 'USD',
            'provenance' => 'actual', 'status' => 'confirmed', 'source_reference' => 'owner:actual-product',
        ]);
        $protected = [
            'last_mile_cost' => '11.11',
            'warehouse_cost' => '22.22',
            'overseas_handling_cost' => '33.33',
            'kol_commission' => '44.44',
            'refund_replacement_loss' => '55.55',
            'other_variable_cost' => '66.66',
        ];
        foreach ($protected as $type => $amount) {
            $order->costEntries()->create([
                'cost_type' => $type, 'amount' => $amount, 'currency' => 'USD',
                'provenance' => 'confirmed_manual', 'status' => 'confirmed', 'source_reference' => 'owner:'.$type,
            ]);
        }

        $summary = $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T12:00:00Z'));

        self::assertSame('99.00', $order->costEntries()->where('cost_type', 'product_cost')->firstOrFail()->amount);
        self::assertGreaterThan(0, $summary['stages']['product_cost']['blocked']);
        foreach ($protected as $type => $amount) {
            self::assertSame($amount, $order->costEntries()->where('cost_type', $type)->firstOrFail()->amount, $type);
        }
    }

    public function test_noop_overlap_recovery_does_not_self_touch_financials_forever(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10T12:00:00Z'));
        $order = $this->readyOrder();
        $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T12:00:00Z'));
        $firstUpdatedAt = $order->financials()->firstOrFail()->updated_at?->toIso8601String();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10T13:00:00Z'));
        $overlap = $this->service()->apply(100, CarbonImmutable::parse('2026-09-10T13:00:00Z'));
        self::assertSame(1, $overlap['candidate_count']);
        self::assertSame(0, $overlap['stages']['product_cost']['applied']);
        self::assertSame(0, $overlap['stages']['fixed_costs']['applied']);
        self::assertSame(0, $overlap['stages']['payment_fee']['applied']);
        self::assertSame($firstUpdatedAt, $order->financials()->firstOrFail()->fresh()->updated_at?->toIso8601String());

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10T14:00:00Z'));
        $settled = $this->service()->preview(100, CarbonImmutable::parse('2026-09-10T14:00:00Z'));
        self::assertSame(0, $settled['candidate_count']);
    }

    public function test_production_apply_is_blocked_when_continuous_delta_flag_is_false(): void
    {
        $this->readyOrder();
        $previousEnv = $this->app['env'];
        $this->app['env'] = 'production';
        config(['erp_accounting.continuous_delta.enabled' => false]);

        try {
            $this->artisan('finance:materialize-continuous-delta', ['--apply' => true])->assertFailed();
            self::assertSame(0, SyncRun::query()->count());
            self::assertSame(0, DB::table('order_cost_entries')->count());
        } finally {
            $this->app['env'] = $previousEnv;
        }
    }

    public function test_mysql_candidate_query_normalizes_session_timezone_to_utc(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            self::markTestSkipped('MySQL-only session timezone contract.');
        }

        DB::statement("SET time_zone = 'SYSTEM'");
        $this->readyOrder();

        $this->service()->preview(100, CarbonImmutable::parse('2026-09-10T12:00:00Z'));

        $session = DB::selectOne('select @@session.time_zone as timezone');
        self::assertSame('+00:00', $session->timezone);
    }

    public function test_scheduler_is_disabled_by_default_and_enabled_at_ten_past_without_changing_woo_hourly_cadence(): void
    {
        $before = collect(app(Schedule::class)->events())
            ->where('description', 'finance.accounting.continuous-delta.hourly');
        self::assertCount(0, $before);

        config([
            'erp_accounting.continuous_delta.enabled' => true,
            'services.woocommerce.order_sync_enabled' => true,
        ]);
        require base_path('routes/console.php');

        $events = collect(app(Schedule::class)->events());
        $finance = $events->where('description', 'finance.accounting.continuous-delta.hourly');
        self::assertCount(1, $finance);
        $financeEvent = $finance->first();
        self::assertSame('10 * * * *', $financeEvent->expression);
        self::assertTrue($financeEvent->withoutOverlapping);
        self::assertSame(50, $financeEvent->expiresAt);
        self::assertTrue($financeEvent->onOneServer);
        self::assertStringContainsString('finance:materialize-continuous-delta --apply --limit=10000 --no-interaction', $financeEvent->command);

        $woo = $events->where('description', 'orders.woocommerce.inbound.hourly');
        self::assertCount(1, $woo);
        self::assertSame('0 * * * *', $woo->first()->expression);
    }

    private function service(): AccountingContinuousDeltaService
    {
        return $this->app->make(AccountingContinuousDeltaService::class);
    }

    private function bareOrder(): Order
    {
        return Order::query()->create([
            'internal_order_number' => 'C1-'.strtoupper(uniqid()),
            'source_type' => 'woocommerce',
            'order_kind' => 'customer',
            'order_date' => '2026-09-10',
            'order_status' => 'pending_shipment',
            'currency' => 'USD',
        ]);
    }

    private function readyOrder(): Order
    {
        $order = $this->bareOrder();
        $order->externalReferences()->create([
            'provider' => 'woocommerce',
            'connection_key' => 'beyourlover.com',
            'reference' => 'C1-WOO-'.strtoupper(uniqid()),
            'provider_modified_at' => now(),
        ]);
        $order->financials()->create([
            'merchandise_list_amount' => '100.00',
            'discount_amount' => '0.00',
            'shipping_revenue' => '0.00',
            'amount_paid' => '100.00',
            'refund_amount' => '0.00',
            'tax_collected' => '0.00',
            'provider_order_total' => '100.00',
            'refund_tax' => '0.00',
            'currency' => 'USD',
            'provider' => 'woocommerce',
            'provider_reference' => (string) $order->id,
            'provider_order_status' => 'processing',
            'provider_modified_at' => now(),
            'revenue_provenance' => 'provider_import',
            'completeness_status' => 'INCOMPLETE',
        ]);
        $product = Product::query()->create([
            'source_type' => 'woocommerce',
            'product_type' => 'simple',
            'sku' => 'C1-SKU-'.strtoupper(uniqid()),
            'name' => 'C1 Product',
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_summary' => $product->name,
            'sku' => $product->sku,
            'quantity' => 1,
            'unit_price' => '100.00',
            'line_revenue' => '100.00',
        ]);
        ProductCostSource::query()->create([
            'product_id' => $product->id,
            'source_key' => 'C1-'.$product->id,
            'unit_cost' => '20.00',
            'currency' => 'USD',
            'provenance' => 'confirmed_source_file',
            'status' => 'confirmed',
            'source_type' => 'inventory_cost_book',
            'source_reference' => 'c1-fixture.csv',
            'effective_from' => '2026-09-01',
            'source_at' => '2026-09-09 00:00:00',
            'confirmed_at' => '2026-09-09 00:00:00',
            'source_fingerprint' => hash('sha256', 'C1|'.$product->id.'|'.uniqid('', true)),
        ]);
        $warehouse = Warehouse::query()->firstOrCreate(
            ['slug' => 'factory'],
            ['code' => 'FACTORY-C1', 'name' => 'China Factory', 'type' => 'warehouse',
                'ownership_type' => 'owned', 'is_active' => true, 'priority' => 100],
        );
        $shipment = new Shipment([
            'shipment_status' => 'pending',
            'warehouse_id' => $warehouse->id,
            'warehouse_selection_source' => 'c1_test',
        ]);
        $order->shipment()->save($shipment);

        return $order->fresh();
    }

    private function stampOrder(Order $order, string $timestamp): void
    {
        DB::table('orders')->where('id', $order->id)->update([
            'created_at' => $timestamp, 'updated_at' => $timestamp,
        ]);
    }

    private function stampOrderAndRelations(Order $order, string $timestamp): void
    {
        $this->stampOrder($order, $timestamp);
        DB::table('order_external_references')->where('order_id', $order->id)->update(['updated_at' => $timestamp]);
        DB::table('order_financials')->where('order_id', $order->id)->update(['updated_at' => $timestamp]);
        DB::table('shipments')->where('order_id', $order->id)->update(['updated_at' => $timestamp]);
    }
}
