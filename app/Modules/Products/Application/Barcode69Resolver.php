<?php

namespace App\Modules\Products\Application;

use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Validation\ValidationException;

/**
 * Product-level logistical barcode. SKU aliases are not automatically trusted.
 * Returns null when no explicitly verified product barcode exists.
 */
final class Barcode69Resolver
{
    public function forProductId(int $productId): ?string
    {
        return Product::query()->whereKey($productId)->value('barcode_69');
    }

    public function requireForChinaSmallPacket(int $productId): string
    {
        $barcode = $this->forProductId($productId);
        if ($barcode === null || $barcode === '') {
            throw ValidationException::withMessages([
                'barcode_69' => '欧洲国内小包缺少69条形码，请先在Product OS包装参数核实。',
            ]);
        }
        return $barcode;
    }
}
