<?php

namespace Tests\Feature\Finance;

use App\Modules\Finance\Application\CostMaterializationService;
use App\Modules\Finance\Application\FxReferenceSettingsService;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\WarehouseCost\WarehouseStorageCostImportService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WarehouseCostMaterializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_currency_allocation_materializes_confirmed_allocated_actual_and_is_idempotent(): void
    {
        [$order] = $this->readyEvidence('EUR', '1.2400', 2);
        $service = app(CostMaterializationService::class);

        $candidate = $service->previewWarehouseCostGate($order)['candidates'][0];
        $this->assertSame('apply', $candidate['materialization_status']);
        $this->assertSame('1.24', $candidate['amount']);
        $this->assertSame('allocated_actual', $candidate['provenance']);
        $this->assertSame('confirmed', $candidate['status']);

        $service->applyWarehouseCostGate($order);
        $entry = $order->costEntries()->where('cost_type', 'warehouse_cost')->firstOrFail()->fresh();        $this->assertSame('1.24', $entry->amount);
        $this->assertSame('EUR', $entry->currency);
        $this->assertSame('allocated_actual', $entry->provenance);
        $this->assertSame('confirmed', $entry->status);
        $this->assertStringContainsString('warehouse-storage-allocation:v1:batch:', $entry->source_reference);

        $second = $service->applyWarehouseCostGate($order->fresh());
        $this->assertCount(1, $second['noop']);
        $this->assertSame(1, $order->costEntries()->where('cost_type', 'warehouse_cost')->count());
    }

    public function test_cross_currency_allocation_uses_fx_bridge_and_preserves_source_money(): void
    {
        app(FxReferenceSettingsService::class)->save(
            ['USD' => 7.5, 'EUR' => 8.0],
            ['USD' => false, 'EUR' => false],
            null,
        );
        [$order] = $this->readyEvidence('USD', '0.6200', 1, sourceCurrency: 'EUR');
        $candidate = app(CostMaterializationService::class)->previewWarehouseCostGate($order)['candidates'][0];

        $this->assertSame('apply', $candidate['materialization_status']);
        $this->assertSame('EUR', $candidate['source_currency']);
        $this->assertSame('0.62', $candidate['source_amount']);
        $this->assertSame('0.66', $candidate['amount']);
        $this->assertSame('0.66', $candidate['posting_fx']['base_amount']);

        app(CostMaterializationService::class)->applyWarehouseCostGate($order);        $entry = $order->costEntries()->where('cost_type', 'warehouse_cost')->firstOrFail()->fresh();
        $this->assertSame('USD', $entry->currency);
        $this->assertSame('0.66', $entry->amount);
        $this->assertSame('USD', $entry->base_currency);
        $this->assertSame('0.66', $entry->base_amount);
    }

    public function test_existing_strong_warehouse_fact_is_never_overwritten(): void
    {
        [$order] = $this->readyEvidence('EUR', '1.2400', 2);
        $order->costEntries()->create([
            'cost_type' => 'warehouse_cost',
            'amount' => '9.99',
            'currency' => 'EUR',
            'provenance' => 'confirmed_manual',
            'status' => 'confirmed',
            'source_reference' => 'owner:warehouse-cost',
        ]);

        $candidate = app(CostMaterializationService::class)->previewWarehouseCostGate($order)['candidates'][0];
        $this->assertSame('blocked', $candidate['materialization_status']);
        $this->assertSame('stronger_or_different_existing_fact', $candidate['blocker']);
        $this->assertSame('9.99', $order->costEntries()->where('cost_type', 'warehouse_cost')->firstOrFail()->amount);
    }

    public function test_command_is_dry_run_first_and_production_apply_requires_flag_and_exact_guards(): void
    {
        [$order] = $this->readyEvidence('EUR', '1.2400', 2);        $this->artisan('finance:materialize-warehouse-cost', ['--order' => $order->id])->assertSuccessful();
        $this->assertFalse($order->costEntries()->where('cost_type', 'warehouse_cost')->exists());

        $old = $this->app['env'];
        $this->app['env'] = 'production';
        putenv('ERP_ACCOUNTING_WAREHOUSE_COST_PRODUCTION_APPLY_ENABLED');
        $args = [
            '--order' => $order->id,
            '--apply' => true,
            '--expect-seen' => '1',
            '--expect-apply' => '1',
            '--expect-noop' => '0',
            '--expect-blocked' => '0',
            '--expect-fx-freeze' => '0',
        ];
        try {
            $this->artisan('finance:materialize-warehouse-cost', $args)->assertFailed();
            putenv('ERP_ACCOUNTING_WAREHOUSE_COST_PRODUCTION_APPLY_ENABLED=true');
            $wrong = $args;
            $wrong['--expect-apply'] = '2';
            $this->artisan('finance:materialize-warehouse-cost', $wrong)->assertFailed();
            $this->assertFalse($order->costEntries()->where('cost_type', 'warehouse_cost')->exists());
            $this->artisan('finance:materialize-warehouse-cost', $args)->assertSuccessful();
            $this->assertDatabaseHas('order_cost_entries', [
                'order_id' => $order->id,
                'cost_type' => 'warehouse_cost',
                'provenance' => 'allocated_actual',
                'status' => 'confirmed',
            ]);        } finally {
            putenv('ERP_ACCOUNTING_WAREHOUSE_COST_PRODUCTION_APPLY_ENABLED');
            $this->app['env'] = $old;
        }
    }

    /** @return array{0:Order,1:Warehouse} */
    private function readyEvidence(string $orderCurrency, string $pool, int $units, string $sourceCurrency = 'EUR'): array
    {
        $warehouse = Warehouse::query()->firstOrCreate(
            ['slug' => 'de'],
            [
                'code' => 'DE-P7-FIN',
                'name' => 'P7 Germany',
                'type' => 'warehouse',
                'ownership_type' => 'third_party',
                'is_active' => true,
                'priority' => 10,
            ],
        );
        $order = Order::query()->create([
            'internal_order_number' => 'P7-FIN-'.strtoupper(uniqid()),
            'source_type' => 'manual',
            'order_kind' => 'customer',
            'order_date' => '2026-01-15',
            'order_status' => 'shipped',
            'currency' => $orderCurrency,
        ]);
        $order->financials()->create([
            'currency' => $orderCurrency,
            'completeness_status' => 'INCOMPLETE',
        ]);        $order->items()->create([
            'product_summary' => 'P7 item',
            'sku' => 'P7-FIN-'.$order->id,
            'quantity' => $units,
            'unit_price' => '1.00',
            'line_revenue' => number_format($units, 2, '.', ''),
        ]);
        $tracking = 'P7-FIN-'.$order->id;
        $order->shipment()->create([
            'warehouse_id' => $warehouse->id,
            'shipment_status' => 'shipped',
            'tracking_number' => $tracking,
        ]);

        $file = tempnam(sys_get_temp_dir(), 'p7-fin-').'.csv';
        $handle = fopen($file, 'wb');
        fputcsv($handle, [
            'warehouse_slug','billing_period_start','billing_period_end','storage_pool_amount',
            'currency','reference_number','tracking_number','outbound_units',
        ]);
        fputcsv($handle, ['de','2026-01-01','2026-01-31',$pool,$sourceCurrency,'C12345',$tracking,$units]);
        fclose($handle);
        app(WarehouseStorageCostImportService::class)->apply($file, 'tianyun');

        return [$order->fresh(), $warehouse];
    }
}
