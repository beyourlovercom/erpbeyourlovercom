<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Infrastructure\Models\PackagingMeasurementRecord;
use App\Modules\Products\Infrastructure\Models\Product;

/**
 * B0–B2 internal read projection for a bounded catalogue batch.
 * The future workspace must apply its existing authorization before calling this
 * service; no endpoint or employee permission is introduced here.
 * Measurement counts are workflow context, not evidence of an adopted standard.
 */
final class PackagingOptimizationCoverageReader
{
    public function __construct(private readonly PackagingOptimizationInputReader $inputs) {}

    /** @return array<int,array<string,mixed>> keyed by canonical product id */
    public function forProductIds(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $products = Product::query()->businessCatalog()->where('is_active', true)
            ->whereIn('id', $ids)->get(['id', 'sku', 'name', 'material_full_name'])->keyBy('id');
        $selected = $products->keys()->all();
        if ($selected === []) {
            return [];
        }
        $inputs = $this->inputs->forProductIds($selected);
        $counts = PackagingMeasurementRecord::query()->whereIn('product_id', $selected)
            ->selectRaw('product_id, status, COUNT(*) as record_count')
            ->groupBy('product_id', 'status')->get()->groupBy('product_id');

        $results = [];
        foreach ($selected as $id) {
            $product = $products[$id];
            $input = $inputs[$id];
            $workflowCounts = ['draft' => 0, 'submitted' => 0, 'approved' => 0, 'rejected' => 0];
            foreach ($counts->get($id, []) as $count) {
                if (array_key_exists($count->status, $workflowCounts)) {
                    $workflowCounts[$count->status] = (int) $count->record_count;
                }
            }
            $complete = $input['source'] === 'adopted_packaging_measurement' && $input['status'] === 'ready';
            $results[$id] = [
                'product_id' => (int) $id,
                'sku' => $product->sku,
                'name' => $product->name,
                'material_name' => $product->material_full_name,
                'input' => $input,
                'measurement_counts' => $workflowCounts,
                'coverage_status' => $complete ? 'complete_adopted_standard' : $input['status'],
                'needs_measurement' => ! $complete,
                'awaiting_review' => $workflowCounts['submitted'] > 0,
                'price_status' => 'awaiting_a4',
            ];
        }
        return $results;
    }
}
