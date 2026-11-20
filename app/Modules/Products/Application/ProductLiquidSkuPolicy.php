<?php

namespace App\Modules\Products\Application;

/** Exact Owner-approved liquid SKUs; do not treat arbitrary BV339A suffixes as liquid. */
final class ProductLiquidSkuPolicy
{
    public const DEFAULT_LIQUID_SKUS = ['BY489A', 'BV339A', 'BV339A-P', 'BV339A-Y'];

    public static function defaultsToLiquid(?string $sku): bool
    {
        return in_array(strtoupper(trim((string) $sku)), self::DEFAULT_LIQUID_SKUS, true);
    }
}
