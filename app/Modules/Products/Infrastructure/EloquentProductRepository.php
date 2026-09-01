<?php

namespace App\Modules\Products\Infrastructure;

use App\Modules\Products\Application\Contracts\ProductRepository;
use App\Modules\Products\Application\SkuMatchKey;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

final class EloquentProductRepository implements ProductRepository
{
    public function findByWooId(int $wooId): ?Model
    {
        return Product::where('woocommerce_product_id', $wooId)->first();
    }

    public function create(array $attributes): Model
    {
        return Product::create($attributes);
    }

    public function update(Model $product, array $attributes): void
    {
        $product->fill($attributes)->save();
    }

    public function delete(Model $product): void
    {
        $product->delete();
    }

    public function findById(int $id): ?Model
    {
        return Product::find($id);
    }

    public function findBySku(string $sku): ?Model
    {
        return Product::where('sku', $sku)->whereNotNull('sku')->first();
    }

    public function findBySkuMatchKey(string $matchKey): ?Model
    {
        // Use PHP-side SkuMatchKey comparison so behaviour is identical across
        // SQLite (case-insensitive LIKE by default) and MySQL (collation-dependent).
        // We narrow candidates with LOWER() for index efficiency, then confirm in PHP.
        $candidates = Product::whereNotNull('sku')
            ->whereRaw('LOWER(sku) = ?', [$matchKey])
            ->get();

        foreach ($candidates as $candidate) {
            if (SkuMatchKey::compute((string) $candidate->getAttribute('sku')) === $matchKey) {
                return $candidate;
            }
        }

        return null;
    }

    public function searchSkus(string $query, int $limit = 20): array
    {
        return Product::query()
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->where(function ($q) use ($query): void {
                $q->where('sku', 'like', $query.'%')
                    ->orWhere('sku', 'like', '%'.$query.'%')
                    ->orWhere('name', 'like', '%'.$query.'%');
            })
            ->orderByRaw('CASE WHEN sku LIKE ? THEN 0 ELSE 1 END, sku', [$query.'%'])
            ->limit($limit)
            ->get(['id', 'sku', 'name'])
            ->map(fn ($p): array => ['id' => $p->id, 'sku' => $p->sku, 'name' => $p->name])
            ->all();
    }

    public function list(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        return Product::query()
            ->when(isset($filters['search']) && $filters['search'] !== '', function ($q) use ($filters): void {
                $search = '%'.$filters['search'].'%';
                $q->where(function ($inner) use ($search): void {
                    $inner->where('sku', 'like', $search)
                        ->orWhere('name', 'like', $search)
                        ->orWhereHas('skuAliases', fn ($a) => $a->where('alias_sku', 'like', $search));
                });
            })
            ->orderBy('name')
            ->paginate(min($perPage, 200))
            ->withQueryString();
    }

    public function totalCount(): int
    {
        return Product::count();
    }

    public function coverageCounts(): array
    {
        $total = Product::count();

        return [
            'total' => $total,
            'weight_filled' => Product::whereNotNull('weight_kg')->count(),
            'shipping_filled' => Product::whereNotNull('shipping_weight_kg')->count(),
        ];
    }
}
