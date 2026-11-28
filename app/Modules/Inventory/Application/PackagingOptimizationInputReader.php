<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Products\Application\ProductShippingWeightStandardReader;

/**
 * Read-only projection of canonical adopted evidence. Never writes product standards,
 * combines unrelated measurements or treats ambiguous product_weight as bare weight.
 */
final class PackagingOptimizationInputReader
{
    public function __construct(
        private readonly ProductPackagingStandardReader $packaging,
        private readonly ProductShippingWeightStandardReader $shipping,
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function forProductIds(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), fn (int $id): bool => $id > 0)));
        $packaging = $this->packaging->forProductIds($ids);
        $shipping = $this->shipping->forProductIds($ids);
        $results = [];
        foreach ($ids as $id) {
            $results[$id] = self::resolve($packaging[$id] ?? [], $shipping[$id] ?? []);
        }
        return $results;
    }

    /**
     * Bare weight is accepted ONLY when explicitly classified by a trusted upstream
     * evidence reader. Existing product_weight records are not automatically classified.
     */
    public static function resolve(array $packaging, array $shipping, array $bare = []): array
    {
        $weight = self::positive($packaging['packaged_weight_kg'] ?? null);
        $dimensions = array_map(self::positive(...), [
            $packaging['packaging_length_cm'] ?? null,
            $packaging['packaging_width_cm'] ?? null,
            $packaging['packaging_height_cm'] ?? null,
        ]);
        if ($weight !== null) {
            return self::result('adopted_packaging_measurement', $weight, $dimensions, false, $packaging);
        }
        $weight = self::positive($shipping['shipping_weight_kg'] ?? null);
        if ($weight !== null) {
            return self::result('adopted_shipping_reference', $weight, [null, null, null], false, $shipping);
        }
        $weight = self::positive($bare['weight_kg'] ?? null);
        if (($bare['weight_kind'] ?? null) === 'explicit_bare_weight'
            && ($bare['evidence_id'] ?? null) && $weight !== null && is_finite($weight * 1.2)) {
            return self::result('bare_weight_estimate', $weight * 1.2, [null, null, null], true, $bare);
        }
        return self::result('missing_weight', null, [null, null, null], false, []);
    }

    private static function result(string $source, ?float $weight, array $dimensions, bool $estimated, array $evidence): array
    {
        return [
            'source' => $source,
            'status' => $weight === null ? 'missing_weight' : (in_array(null, $dimensions, true) ? 'missing_dimensions' : 'ready'),
            'weight_kg' => $weight,
            'length_cm' => $dimensions[0],
            'width_cm' => $dimensions[1],
            'height_cm' => $dimensions[2],
            'estimated' => $estimated,
            'evidence' => $evidence,
        ];
    }

    private static function positive(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value > 0 ? (float) $value : null;
    }
}
