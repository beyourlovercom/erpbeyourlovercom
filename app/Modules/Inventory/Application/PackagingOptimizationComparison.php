<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Products\Application\PackagingPresetCatalog;

/**
 * B2 read-only, single-parcel geometry comparison. Input measurements are
 * separate complete candidate parcels, never fabricated from preset names.
 * No quote, adoption, or safety approval is produced.
 */
final class PackagingOptimizationComparison
{
    public function __construct(
        private readonly PackagingPresetCatalog $presets,
        private readonly PackagingOptimizationGeometry $geometry,
    ) {}

    public function compare(array $current, array $candidates, string $rule, mixed $divisor = null): array
    {
        $baseline = $this->geometry->calculate($current, $rule, $divisor);
        $rows = [];
        foreach ($this->presets->keys() as $key) {
            $candidate = $candidates[$key] ?? null;
            $provided = is_array($candidate);
            $trial = $this->geometry->calculate($provided ? $candidate : [], $rule, $divisor);
            $old = $baseline['reference_chargeable_weight_kg'];
            $new = $trial['reference_chargeable_weight_kg'];
            $oldVolume = $baseline['volume_cm3'];
            $newVolume = $trial['volume_cm3'];
            $rows[$key] = [
                'method_key' => $key,
                'input_status' => $provided ? 'candidate_input' : 'not_measured',
                'geometry' => $trial,
                'reference_weight_difference_kg' => $old !== null && $new !== null ? $old - $new : null,
                'volume_difference_cm3' => $oldVolume !== null && $newVolume !== null ? $oldVolume - $newVolume : null,
                'price_status' => 'awaiting_a4',
                'approval_status' => 'not_approved',
            ];
        }

        return [
            'scope' => 'single_parcel_geometry_only',
            'baseline' => $baseline,
            'candidates' => $rows,
            'unsupported_method_keys' => array_values(array_diff(array_keys($candidates), array_keys($rows))),
            'price_status' => 'awaiting_a4',
        ];
    }
}
