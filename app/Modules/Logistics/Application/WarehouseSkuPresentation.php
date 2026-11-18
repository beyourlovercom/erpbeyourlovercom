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
        $sku = trim((string) ($item->final_sku ?: $item->resolved_fulfillment_sku ?: $item->sku));
        if (strcasecmp($sku, 'gift') === 0) {
            return '实际礼品SKU未登记';
        }

        return $sku;
    }

    public static function verified(OrderItem $item): bool
    {
        $sku = trim((string) ($item->final_sku ?: $item->resolved_fulfillment_sku ?: $item->sku));

        return $sku !== '' && strcasecmp($sku, 'gift') !== 0
            && (! filled($item->final_sku)
                || ! filled($item->resolved_fulfillment_sku)
                || strcasecmp(trim((string) $item->final_sku), trim((string) $item->resolved_fulfillment_sku)) === 0);
    }
}
