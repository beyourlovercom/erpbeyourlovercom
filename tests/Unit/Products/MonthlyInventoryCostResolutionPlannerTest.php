<?php

namespace Tests\Unit\Products;

use App\Modules\Products\Application\Contracts\ProductRepository;
use App\Modules\Products\Application\MonthlyInventoryCostResolutionPlanner;
use App\Modules\Products\Infrastructure\Models\Product;
use PHPUnit\Framework\TestCase;

final class MonthlyInventoryCostResolutionPlannerTest extends TestCase
{
    /** @return array<string,mixed> */
    private function row(string $key, string $cost, string $status = 'extracted', string $sku = 'VA617A'): array
    {
        return [
            'source_sheet' => '10月进销存', 'source_row' => 2,
            'source_variant_key' => $key, 'effective_from' => '2026-10-01',
            'product_name' => 'VA617A（国内新版）', 'factory_code' => '1VA617A5C0100',
            'unit_cost' => $cost, 'currency' => 'CNY',
            'base_sku' => $sku, 'extraction_status' => $status,
        ];
    }

    private function planner(): MonthlyInventoryCostResolutionPlanner
    {
        $repo = $this->createStub(ProductRepository::class);
        $repo->method('searchSkus')->willReturnCallback(static fn (string $query) => [
            ['id' => $query === 'VA617A' ? 7 : 8, 'sku' => $query, 'name' => 'ERP '.$query],
        ]);
        $repo->method('findById')->willReturnCallback(static function (int $id) {
            if ($id !== 7 && $id !== 8) {
                return null;
            }
            $p = new Product;
            $p->forceFill(['id' => $id, 'sku' => $id === 7 ? 'VA617A' : 'BY489A', 'name' => 'Verified ERP Product']);

            return $p;
        });

        return new MonthlyInventoryCostResolutionPlanner($repo);
    }

    public function test_price_conflict_never_silently_selects_last_row(): void
    {
        $rows = [$this->row('source|row2', '28.000000000000'), $this->row('source|row3', '29.000000000000')];
        $result = $this->planner()->resolve($rows);
        $this->assertSame(0, $result['summary']['ready']);
        $this->assertSame(1, $result['summary']['price_conflicts']);
        $this->assertContains('source_price_conflict', $result['groups'][0]['blockers']);
        $this->assertSame([], $result['canonical_rows_by_month']);
    }

    public function test_operator_can_choose_one_source_with_reason(): void
    {
        $rows = [$this->row('source|row2', '28.000000000000'), $this->row('source|row3', '29.000000000000')];
        $result = $this->planner()->resolve($rows, [
            '2026-10-01|VA617A' => ['source_variant_key' => 'source|row2', 'reason' => '采购确认'],
        ]);
        $this->assertSame(1, $result['summary']['ready']);
        $this->assertSame('28.000000000000', $result['canonical_rows_by_month']['2026-10-01'][0]['unit_cost']);
        $this->assertSame('source|row2', $result['canonical_rows_by_month']['2026-10-01'][0]['source_variant_key']);
    }

    public function test_manual_actual_cost_requires_reason_and_verified_erp_product(): void
    {
        $row = $this->row('source|row2', '28.000000000000', 'needs_confirmation', 'BY489A');
        $block = $this->planner()->resolve([$row], [
            '2026-10-01|BY489A' => ['product_id' => 8, 'manual_cost_cny' => '19.5'],
        ]);
        $this->assertSame(1, $block['summary']['blocked']);
        $this->assertContains('manual_cost_reason_required', $block['groups'][0]['blockers']);

        $ready = $this->planner()->resolve([$row], [
            '2026-10-01|BY489A' => ['product_id' => 8, 'manual_cost_cny' => '19.5', 'reason' => '财务核定实际成本'],
        ]);
        $this->assertSame(1, $ready['summary']['ready']);
        $this->assertSame(1, $ready['summary']['manual_costs']);
        $this->assertSame('19.500000000000', $ready['canonical_rows_by_month']['2026-10-01'][0]['unit_cost']);
        $this->assertSame('CNY', $ready['canonical_rows_by_month']['2026-10-01'][0]['currency']);
    }

    public function test_stale_or_invalid_source_selection_stays_blocked(): void
    {
        $result = $this->planner()->resolve([$this->row('source|row2', '28.000000000000')], [
            '2026-10-01|VA617A' => ['source_variant_key' => 'not-in-file', 'reason' => 'test'],
        ]);
        $this->assertContains('selected_source_not_in_group', $result['groups'][0]['blockers']);
        $this->assertSame([], $result['canonical_rows_by_month']);
    }
}
