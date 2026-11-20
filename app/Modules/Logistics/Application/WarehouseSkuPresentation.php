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
        $final = trim((string) $item->final_sku);
        $source = trim((string) $item->sku);
        $resolved = trim((string) $item->resolved_fulfillment_sku);
        if ($final !== '') {
            return strcasecmp($final, 'gift') !== 0
                && ($resolved === '' || strcasecmp($final, $resolved) === 0);
        }

        // Preserve the pre-Final-SKU safeguard for legacy rows. A different
        // resolved SKU with no verified final SKU is not safe for dispatch.
        return $source !== '' && strcasecmp($source, 'gift') !== 0
            && ($resolved === '' || strcasecmp($source, $resolved) === 0);
    }
}
