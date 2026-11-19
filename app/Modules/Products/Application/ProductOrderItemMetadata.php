<?php

namespace App\Modules\Products\Application;

use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductSkuAlias;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read-only Product master metadata for order line items; never changes SKU routing. */
final class ProductOrderItemMetadata
{
    /** @param Collection<int, mixed> $orders
     * @return array<string, Product>
     */
    public function fallbackProductsForOrders(Collection $orders): array
    {
        $keys = [];
        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                if ($item->product !== null) {
                    continue;
                }
                foreach ([$item->sku, $item->source_sku, $item->final_sku] as $raw) {
                    $key = SkuMatchKey::compute((string) ($raw ?? ''));
                    if ($key !== null) {
                        $keys[$key] = true;
                    }
                }
            }
        }
        if ($keys === []) {
            return [];
        }
        $matches = [];
        $add = static function (string $key, Product $product) use (&$matches): void {
            $matches[$key][(int) $product->id] = $product;
        };
        foreach (Product::query()->whereIn(DB::raw('LOWER(TRIM(sku))'), array_keys($keys))->get() as $product) {
            $add(SkuMatchKey::compute((string) $product->sku), $product);
        }
        foreach (ProductSkuAlias::query()->with('product')->where('is_active', true)
            ->whereIn('match_key', array_keys($keys))->get() as $alias) {
            if ($alias->product !== null) {
                $add((string) $alias->match_key, $alias->product);
            }
        }
        // An ambiguous alias/canonical collision is deliberately unresolved.
        $resolved = [];
        foreach ($matches as $key => $candidates) {
            if (count($candidates) === 1) {
                $resolved[$key] = reset($candidates);
            }
        }

        return $resolved;
    }

    /** @param array<string, Product> $fallback */
    public function productForItem(Model $item, array $fallback): ?Product
    {
        if ($item->product !== null) {
            return $item->product;
        }
        $candidates = [];
        foreach ([$item->sku, $item->source_sku, $item->final_sku] as $raw) {
            $key = SkuMatchKey::compute((string) ($raw ?? ''));
            if ($key !== null && isset($fallback[$key])) {
                $candidates[(int) $fallback[$key]->id] = $fallback[$key];
            }
        }

        return count($candidates) === 1 ? reset($candidates) : null;
    }
}
