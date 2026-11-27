<?php

namespace App\Modules\Products\Application;

final class ProductCatalogExclusion
{
    public static function excludes(?string $sku): bool
    {
        return str_starts_with(strtoupper(trim((string) $sku)), '00W');
    }
}
