<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\Inventory\Application\PackagingOptimizationGeometry;
use App\Modules\Inventory\Application\PackagingOptimizationInputReader;
use PHPUnit\Framework\TestCase;

final class PackagingOptimizationTest extends TestCase
{
    private function sample(): array
    {
        return ['weight_kg' => 2.232, 'length_cm' => 39, 'width_cm' => 25.5, 'height_cm' => 18.7];
    }

    public function test_handoff_geometry_sample_and_conservative_rounding(): void
    {
        $r = (new PackagingOptimizationGeometry)->calculate($this->sample(), 'max_actual_volumetric', 6000);
        self::assertSame('candidate', $r['status']);
        self::assertEqualsWithDelta(18597.15, $r['volume_cm3'], 0.00001);
        self::assertEqualsWithDelta(3.099525, $r['volumetric_weight_kg'], 0.000001);
        self::assertEqualsWithDelta(13392, $r['target_volume_cm3'], 0.00001);
        self::assertSame(13.4, $r['candidate_height_cm']);
        self::assertLessThanOrEqual(2.232, 39 * 25.5 * $r['candidate_height_cm'] / 6000);
        self::assertSame('unverified', $r['feasibility']);
        self::assertSame('awaiting_a4', $r['price_status']);
    }

    public function test_rule_modes_and_actual_dominance(): void
    {
        $g = new PackagingOptimizationGeometry;
        self::assertSame('actual_only', $g->calculate($this->sample(), 'actual_only')['status']);
        self::assertSame('rule_unconfirmed', $g->calculate($this->sample(), 'threshold', 6000)['status']);
        self::assertSame('rule_unconfirmed', $g->calculate($this->sample(), 'max_actual_volumetric')['status']);
        $r = $g->calculate([...$this->sample(), 'weight_kg' => 4], 'max_actual_volumetric', 8000);
        self::assertSame('no_volumetric_excess', $r['status']);
        self::assertSame(0.0, $r['reference_weight_reduction_kg']);
        self::assertNull($r['candidate_height_cm']);
    }

    public function test_missing_inputs_and_infeasible_height(): void
    {
        $g = new PackagingOptimizationGeometry;
        self::assertSame('missing_weight', $g->calculate([], 'max_actual_volumetric', 6000)['status']);
        self::assertSame('missing_dimensions', $g->calculate([...$this->sample(), 'height_cm' => 0], 'max_actual_volumetric', 6000)['status']);
        self::assertSame('target_not_feasible', $g->calculate($this->sample(), 'max_actual_volumetric', 6000, 14)['status']);
        self::assertSame('height_constraint_met_only', $g->calculate($this->sample(), 'max_actual_volumetric', 6000, 13)['feasibility']);
    }

    public function test_adopted_packaging_has_priority_without_double_increment(): void
    {
        $r = PackagingOptimizationInputReader::resolve(
            ['packaged_weight_kg' => '2.232', 'packaging_length_cm' => '39', 'packaging_width_cm' => '25.5', 'packaging_height_cm' => '18.7', 'measurement_record_id' => 42],
            ['shipping_weight_kg' => '3.5'],
        );
        self::assertSame('ready', $r['status']);
        self::assertSame(2.232, $r['weight_kg']);
        self::assertFalse($r['estimated']);
        self::assertSame(42, $r['evidence']['measurement_record_id']);
    }

    public function test_shipping_reference_does_not_inherit_other_dimensions_or_increment(): void
    {
        $r = PackagingOptimizationInputReader::resolve(['packaging_length_cm' => 39], ['shipping_weight_kg' => '2.5']);
        self::assertSame('missing_dimensions', $r['status']);
        self::assertSame(2.5, $r['weight_kg']);
        self::assertNull($r['length_cm']);
        self::assertFalse($r['estimated']);
    }

    public function test_bare_weight_requires_explicit_classification_and_evidence(): void
    {
        $r = PackagingOptimizationInputReader::resolve([], [], ['weight_kind' => 'product_weight', 'weight_kg' => 2, 'evidence_id' => 1]);
        self::assertSame('missing_weight', $r['status']);
        self::assertNull($r['weight_kg']);
        $r = PackagingOptimizationInputReader::resolve([], [], ['weight_kind' => 'explicit_bare_weight', 'weight_kg' => 2, 'evidence_id' => 1]);
        self::assertSame(2.4, $r['weight_kg']);
        self::assertTrue($r['estimated']);
        self::assertSame('missing_dimensions', $r['status']);
    }
}
