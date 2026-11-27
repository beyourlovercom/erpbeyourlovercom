<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

/**
 * Mathematical candidate only. No fee, billed weight, pricing tier, provider rule,
 * persistence, safety approval or shipping operation is implemented here.
 */
final class PackagingOptimizationGeometry
{
    public function calculate(array $input, string $rule, mixed $divisor = null, mixed $minimumSafeHeight = null): array
    {
        $result = ['status' => 'rule_unconfirmed', 'price_status' => 'awaiting_a4', 'geometry_only' => true,
            'volume_cm3' => null, 'volumetric_weight_kg' => null, 'reference_chargeable_weight_kg' => null,
            'target_volume_cm3' => null, 'target_height_cm' => null, 'candidate_height_cm' => null,
            'volume_reduction_fraction' => null, 'reference_weight_reduction_kg' => null,
            'feasibility' => 'unverified'];
        $weight = self::positive($input['weight_kg'] ?? null);
        if ($weight === null) {
            return [...$result, 'status' => 'missing_weight'];
        }
        if ($rule === 'actual_only') {
            return [...$result, 'status' => 'actual_only', 'reference_chargeable_weight_kg' => $weight];
        }
        $d = self::positive($divisor);
        if ($rule !== 'max_actual_volumetric' || $d === null) {
            return $result;
        }
        $l = self::positive($input['length_cm'] ?? null);
        $w = self::positive($input['width_cm'] ?? null);
        $h = self::positive($input['height_cm'] ?? null);
        if ($l === null || $w === null || $h === null) {
            return [...$result, 'status' => 'missing_dimensions'];
        }
        $area = $l * $w;
        $volume = $area * $h;
        $target = $weight * $d;
        $volumetric = $volume / $d;
        if (!is_finite($volume) || !is_finite($target) || !is_finite($volumetric) || $area <= 0 || $target <= 0 || $volume <= 0) {
            return [...$result, 'status' => 'invalid_geometry'];
        }
        $targetHeight = $target / $area;
        if (!is_finite($targetHeight)) {
            return [...$result, 'status' => 'invalid_geometry'];
        }
        $result = [...$result,
            'volume_cm3' => $volume,
            'volumetric_weight_kg' => $volumetric,
            'reference_chargeable_weight_kg' => max($weight, $volumetric),
            'target_volume_cm3' => $target,
            'target_height_cm' => $targetHeight,
            'volume_reduction_fraction' => max(0.0, 1.0 - $target / $volume),
            'reference_weight_reduction_kg' => max(0.0, $volumetric - $weight),
        ];
        if ($volumetric <= $weight) {
            return [...$result, 'status' => 'no_volumetric_excess', 'candidate_height_cm' => null];
        }
        // Round DOWN to 0.1 cm. The conservative post-check prevents floating point
        // boundary error from creating a candidate above the theoretical limit.
        $candidate = floor($targetHeight * 10.0) / 10.0;
        if ($area * $candidate > $target) {
            $candidate = round($candidate - 0.1, 1);
        }
        $minimum = self::positive($minimumSafeHeight);
        if ($candidate <= 0 || ($minimumSafeHeight !== null && $minimum === null) || ($minimum !== null && $candidate < $minimum)) {
            return [...$result, 'status' => 'target_not_feasible', 'feasibility' => 'not_feasible_at_fixed_base'];
        }
        return [...$result, 'status' => 'candidate', 'candidate_height_cm' => $candidate,
            'feasibility' => $minimum === null ? 'unverified' : 'height_constraint_met_only'];
    }

    private static function positive(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value > 0 ? (float) $value : null;
    }
}
