<?php

namespace App\Modules\Products\Application;

use Illuminate\Support\Facades\DB;

final class ProductShippingWeightStandardReader
{
    /** @return array<int,array<string,mixed>> */
    public function forProductIds(array $productIds): array
    {
        $ids = collect($productIds)->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($ids === []) return [];

        return DB::table('products as product')
            ->join('product_weight_evidences as evidence', 'evidence.id', '=', 'product.shipping_weight_evidence_id')
            ->whereIn('product.id', $ids)
            ->whereNotNull('product.shipping_weight_kg')
            ->where('product.shipping_weight_kg', '>', 0)
            ->where('evidence.status', 'adopted')
            ->where('evidence.weight_kind', 'shipping_reference_weight')
            ->whereColumn('evidence.product_id', 'product.id')
            ->select([
                'product.id as product_id',
                'product.shipping_weight_kg',
                'product.shipping_weight_evidence_id',
                'evidence.source_type',
                'evidence.source_reference',
                'evidence.observed_at',
            ])
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->product_id => [
                'product_id' => (int) $row->product_id,
                'shipping_weight_kg' => number_format((float) $row->shipping_weight_kg, 4, '.', ''),
                'evidence_id' => (int) $row->shipping_weight_evidence_id,
                'source_type' => $row->source_type,
                'source_reference' => $row->source_reference,
                'observed_at' => $row->observed_at,
            ]])
            ->all();
    }
}
