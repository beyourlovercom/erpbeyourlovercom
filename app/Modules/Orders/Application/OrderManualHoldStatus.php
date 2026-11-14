<?php

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Infrastructure\Models\Order;

/**
 * One staff-selected primary customer status. The bucket selects its work queue;
 * neither setting the status nor clearing it fabricates fulfillment/Woo facts.
 */
final class OrderManualHoldStatus
{
    public const EXCEPTION_CODES = ['exception', 'missing_info', 'renew', 'claims', 'freight_product_cost_follow_up'];
    public const RETURN_CODES = ['returned_follow_up'];
    public const CODES = [...self::EXCEPTION_CODES, ...self::RETURN_CODES];

    public const LEGACY_MARKERS = [
        'workflow_aux_missing_info',
        'workflow_aux_renew',
        'workflow_aux_claims',
        'workflow_aux_freight_product_cost_follow_up',
        'workflow_aux_returned_follow_up',
    ];

    public const EXCEPTION_MARKERS = [
        'exception', 'workflow_aux_missing_info', 'workflow_aux_renew',
        'workflow_aux_claims', 'workflow_aux_freight_product_cost_follow_up',
    ];

    public const HOLD_MARKERS = [...self::LEGACY_MARKERS, 'exception'];

    public static function bucket(string $code): ?string
    {
        if (in_array($code, self::EXCEPTION_CODES, true)) {
            return 'exception';
        }
        if (in_array($code, self::RETURN_CODES, true)) {
            return 'returned_follow_up';
        }

        return null;
    }

    /**
     * Legacy multi-tag orders: choose ONE exception before a return tag, rather
     * than displaying a pile of competing historical selections. The next
     * staff selection clears old status tags atomically; no mass data rewrite.
     */
    public static function selected(Order $order): ?string
    {
        if (in_array((string) $order->workflow_status, self::CODES, true)) {
            return (string) $order->workflow_status;
        }
        $order->loadMissing('operationalMarkers');
        $legacy = $order->operationalMarkers
            ->filter(fn ($marker) => in_array((string) $marker->marker, self::EXCEPTION_MARKERS, true))
            ->sortByDesc('id')->first();
        if ($legacy) {
            return $legacy->marker === 'exception'
                ? 'exception'
                : substr($legacy->marker, strlen('workflow_aux_'));
        }
        $return = $order->operationalMarkers
            ->filter(fn ($marker) => $marker->marker === 'workflow_aux_returned_follow_up')
            ->first();

        return $return ? 'returned_follow_up' : null;
    }
}
