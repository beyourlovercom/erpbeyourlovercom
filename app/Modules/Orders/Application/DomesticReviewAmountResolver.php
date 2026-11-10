<?php

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Infrastructure\Models\Order;

/** Read-only list projection; never create payment facts for fulfillment segments. */
final class DomesticReviewAmountResolver
{
    /** @return array{amount:?string,currency:?string,source:string} */
    public function resolve(Order $order): array
    {
        $origin = $order->fulfillment_parent_id !== null ? ($order->fulfillmentParent ?? $order) : $order;
        $warehouse = $order->shipment?->warehouse;
        $channel = strtolower(trim(implode(' ', array_filter([
            $warehouse?->code, $warehouse?->slug, $warehouse?->name,
        ]))));

        if ($origin->order_kind === OrderKind::KolFulfillment) {
            return ['amount' => '0.00', 'currency' => 'USD', 'source' => '网红履约订单 · 不计销售金额'];
        }

        // Chunyi is an external Amazon fulfillment partner, not a generic manual order.
        if (str_contains($channel, '春意') || str_contains($channel, 'chunyi')) {
            return ['amount' => '29.00', 'currency' => 'USD', 'source' => '春意合作方履约 · 默认订单金额'];
        }

        if (! in_array($origin->source_type, [OrderSourceType::WooCommerce, OrderSourceType::Website], true)) {
            return ['amount' => null, 'currency' => null, 'source' => '非网站订单 · 无金额规则'];
        }

        $money = $origin->financials?->provider_order_total;
        if ($money === null) {
            return ['amount' => null, 'currency' => null, 'source' => '网站原始总金额尚未同步'];
        }

        return [
            'amount' => number_format((float) $money, 2, '.', ''),
            'currency' => strtoupper(trim((string) ($origin->financials?->currency ?: $origin->currency ?: 'USD'))),
            'source' => $origin === $order ? '网站真实订单总金额' : '母订单总金额 · 分仓不重复计收',
        ];
    }
}
