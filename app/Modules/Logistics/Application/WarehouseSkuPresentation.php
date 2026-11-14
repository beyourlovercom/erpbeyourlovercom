<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Orders\Infrastructure\Models\OrderItem;

/**
 * Never present a placeholder as a verified warehouse SKU.
 * A historical gift with no selected physical mapping is explicitly unresolved.
 */
final class WarehouseSkuPresentation
{
    public static function sku(OrderItem $item): string
    {
        $sku = trim((string) $item->sku);
        if (strcasecmp($sku, 'gift') === 0) {
            return '实际礼品SKU未登记';
        }
        return $sku;
    }

    public static function verified(OrderItem $item): bool
    {
        return trim((string) $item->sku) !== ''
            && strcasecmp(trim((string) $item->sku), 'gift') !== 0
            && (! filled($item->resolved_fulfillment_sku)
                || strcasecmp(trim((string) $item->sku), trim((string) $item->resolved_fulfillment_sku)) === 0);
    }
}
