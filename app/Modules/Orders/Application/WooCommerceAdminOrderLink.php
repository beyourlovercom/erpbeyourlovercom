<?php

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Infrastructure\Models\Order;

final class WooCommerceAdminOrderLink
{
    public function forOrder(Order $order): ?string
    {
        if ($order->source_type !== OrderSourceType::WooCommerce
            || $order->order_kind !== OrderKind::Customer
            || $order->archived_at !== null) {
            return null;
        }

        $connectionKey = trim((string) config('services.woocommerce.connection_key', ''));
        $baseUrl = trim((string) config('services.woocommerce.url', ''));
        $parts = parse_url($baseUrl);
        if ($connectionKey === '' || ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! in_array(strtolower((string) ($parts['host'] ?? '')), ['www.beyourlover.com', 'beyourlover.com'], true)
            || ! in_array((string) ($parts['path'] ?? ''), ['', '/'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        // Never infer the Woo ID from the ERP ID or the customer-facing order number.
        $references = $order->externalReferences()
            ->where('provider', 'woocommerce')
            ->get(['connection_key', 'reference']);
        if ($references->count() !== 1 || (string) $references->first()->connection_key !== $connectionKey) {
            return null;
        }

        $reference = (string) $references->first()->reference;
        if (! preg_match('/^[1-9][0-9]*$/D', $reference) || filter_var($reference, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        $mode = (string) config('services.woocommerce.admin_order_editor_mode', 'hpos');
        if ($mode === 'hpos') {
            return 'https://www.beyourlover.com/wp-admin/admin.php?page=wc-orders&action=edit&id='.$reference;
        }
        if ($mode === 'legacy') {
            return 'https://www.beyourlover.com/wp-admin/post.php?post='.$reference.'&action=edit';
        }

        return null;
    }
}
