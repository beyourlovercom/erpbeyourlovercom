<?php

namespace App\Modules\Products\Application;

use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only, canonical Product OS attention filters.
 *
 * These predicates are shared by dashboard counts and /products/list so a
 * dashboard card always drills into exactly the same population it counts.
 * Unknown fields mean "missing data", never stock=0, published or inactive.
 */
final class ProductAttentionFilters
{
    public const KINDS = [
        'needs_data',
        'missing_image',
        'missing_brand',
        'missing_barcode_69',
        'missing_shipping_weight',
        'missing_dimensions',
        'pending_review',
    ];

    public static function apply(Builder $query, string $kind, string $connectionKey): Builder
    {
        if (! in_array($kind, self::KINDS, true)) {
            return $query;
        }

        return match ($kind) {
            'missing_image' => self::withoutPrimaryWooImage($query, $connectionKey),
            'missing_brand' => self::missingText($query, 'brand'),
            'missing_barcode_69' => self::missingText($query, 'barcode_69'),
            'missing_shipping_weight' => $query->whereNull('shipping_weight_kg'),
            'missing_dimensions' => $query->where(fn (Builder $q) => $q
                ->whereNull('length_cm')->orWhereNull('width_cm')->orWhereNull('height_cm')),
            'pending_review' => $query->whereHas('parameterSubmissions',
                fn (Builder $q) => $q->where('status', 'submitted')),
            'needs_data' => $query->where(fn (Builder $q) => $q
                ->whereNull('brand')->orWhere('brand', '')
                ->orWhereNull('barcode_69')->orWhere('barcode_69', '')
                ->orWhereNull('shipping_weight_kg')
                ->orWhereNull('length_cm')->orWhereNull('width_cm')->orWhereNull('height_cm')
                ->orWhereDoesntHave('media', fn (Builder $m) => self::primaryWooImage($m, $connectionKey))),
        };
    }

    private static function missingText(Builder $query, string $column): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull($column)->orWhere($column, ''));
    }

    private static function withoutPrimaryWooImage(Builder $query, string $connectionKey): Builder
    {
        return $query->whereDoesntHave('media', fn (Builder $q) => self::primaryWooImage($q, $connectionKey));
    }

    private static function primaryWooImage(Builder $query, string $connectionKey): Builder
    {
        return $query->where('provider', 'woocommerce')
            ->where('connection_key', $connectionKey)
            ->where('is_primary', true)
            ->where(fn (Builder $q) => $q
                ->whereNotNull('source_url')->orWhereNotNull('storage_path'));
    }

    public static function counts(string $connectionKey): array
    {
        $result = [];
        foreach (self::KINDS as $kind) {
            $result[$kind] = self::apply(Product::query(), $kind, $connectionKey)->count();
        }

        return $result;
    }
}
