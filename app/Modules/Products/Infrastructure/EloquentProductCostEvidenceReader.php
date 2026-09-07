<?php

namespace App\Modules\Products\Infrastructure;

use App\Modules\Products\Application\Contracts\ProductCostEvidenceReader;
use Illuminate\Database\Eloquent\Model;

final class EloquentProductCostEvidenceReader implements ProductCostEvidenceReader
{
    public function read(int|string $orderId): array
    {
        $itemClass = 'App\\Modules\\Orders\\Infrastructure\\Models\\OrderItem';
        $items = $itemClass::query()->with('product')->where('order_id', $orderId)->orderBy('id')->get();

        return $items->map(function (Model $item): array {
            $product = $item->product;
            return [
                'item_id' => (int) $item->getKey(),
                'product_id' => $product?->getKey() === null ? null : (int) $product->getKey(),
                'quantity' => $item->quantity,
                'cost_currency' => $product?->cost_currency,
                'standard_purchase_cost' => $product?->standard_purchase_cost,
                'standard_production_cost' => $product?->standard_production_cost,
                'standard_packaging_cost' => $product?->standard_packaging_cost,
                'updated_at' => $product?->updated_at,
            ];
        })->all();
    }
}
