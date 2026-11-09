<?php

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Infrastructure\Models\Order;
use Carbon\CarbonInterface;

/** The source order owns the business date; fulfillment rows have their own audit timestamps. */
final class OrderBusinessDateResolver
{
    /** @return array{date:?string, source:?string} */
    public function domesticReview(Order $order): array
    {
        $origin = $order->fulfillment_parent_id !== null
            ? ($order->fulfillmentParent ?? $order)
            : $order;
        $fromParent = $origin !== $order;

        if ($origin->order_kind === OrderKind::KolFulfillment) {
            $agreed = $origin->kolCollaboration?->agreed_at;
            if ($agreed instanceof CarbonInterface) {
                return $this->result($agreed, $fromParent ? '母订单合作日期' : '合作日期');
            }

            // An explicitly dated manual KOL order uses order_date as its cooperation date.
            if ($origin->source_type === OrderSourceType::Manual && $origin->order_date !== null) {
                return $this->result($origin->order_date, $fromParent ? '母订单合作日期/订单日期' : '网红订单日期');
            }

            return $this->result($origin->created_at ?? $origin->order_date, $fromParent ? '母订单创建日期' : '订单创建日期');
        }

        if ($origin->financials?->paid_at !== null) {
            return $this->result($origin->financials->paid_at, $fromParent ? '母订单支付日期' : '支付日期');
        }

        // Split fulfillment rows must never invent their own payment timestamp.
        if ($fromParent) {
            return $this->result($origin->order_date ?? $origin->created_at, '母订单日期');
        }

        return ['date' => null, 'source' => null];
    }

    /** @return array{date:?string, source:?string} */
    private function result(?CarbonInterface $date, string $source): array
    {
        return ['date' => $date?->format('m-d'), 'source' => $date ? $source : null];
    }
}
