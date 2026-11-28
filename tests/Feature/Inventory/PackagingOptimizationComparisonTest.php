<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Application\PackagingOptimizationComparison;
use Tests\TestCase;

final class PackagingOptimizationComparisonTest extends TestCase
{
    public function test_four_existing_presets_compare_only_supplied_measurements_and_keep_negative_differences(): void
    {
        $current = ['weight_kg' => 2.232, 'length_cm' => 39, 'width_cm' => 25.5, 'height_cm' => 18.7];
        $r = app(PackagingOptimizationComparison::class)->compare($current, [
            'carton' => [...$current, 'height_cm' => 13.4],
            'direct_mailer' => [...$current, 'weight_kg' => 4],
            'document_bubble_mailer' => ['length_cm' => 39, 'width_cm' => 25.5, 'height_cm' => 13.4],
            'unknown' => $current,
        ], 'max_actual_volumetric', 6000);
        self::assertSame(['direct_mailer', 'carton', 'document_bubble_mailer', 'bubble_wrap_cut'], array_keys($r['candidates']));
        self::assertEqualsWithDelta(0.867525, $r['candidates']['carton']['reference_weight_difference_kg'], 0.000001);
        self::assertLessThan(0, $r['candidates']['direct_mailer']['reference_weight_difference_kg']);
        self::assertNull($r['candidates']['document_bubble_mailer']['reference_weight_difference_kg']);
        self::assertSame('missing_weight', $r['candidates']['document_bubble_mailer']['geometry']['status']);
        self::assertSame('not_measured', $r['candidates']['bubble_wrap_cut']['input_status']);
        self::assertNull($r['candidates']['bubble_wrap_cut']['volume_difference_cm3']);
        self::assertSame(['unknown'], $r['unsupported_method_keys']);
        self::assertSame('awaiting_a4', $r['price_status']);
        self::assertSame('not_approved', $r['candidates']['carton']['approval_status']);
    }

    public function test_no_unconfirmed_billing_rule_or_incomplete_baseline_produces_comparison_weight(): void
    {
        $candidate = ['weight_kg' => 1, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10];
        $service = app(PackagingOptimizationComparison::class);
        $r = $service->compare($candidate, ['carton' => $candidate], 'threshold', 6000);
        self::assertNull($r['candidates']['carton']['reference_weight_difference_kg']);
        $r = $service->compare([], ['carton' => $candidate], 'actual_only');
        self::assertNull($r['candidates']['carton']['reference_weight_difference_kg']);
        $r = $service->compare(['weight_kg' => 2], ['carton' => $candidate], 'actual_only');
        self::assertSame(1.0, $r['candidates']['carton']['reference_weight_difference_kg']);
        self::assertNull($r['candidates']['carton']['geometry']['candidate_height_cm']);
        self::assertSame('awaiting_a4', $r['candidates']['carton']['price_status']);
    }
}
