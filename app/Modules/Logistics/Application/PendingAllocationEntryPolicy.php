<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\Eloquent\Builder;

final class PendingAllocationEntryPolicy
{
    /** Woo statuses remain fully synchronized; this gates pending allocation only. */
    public function constrain(Builder $query): Builder
    {
        return $query->where(function (Builder $eligible): void {
            // KOL fulfillment orders require no separate manager approval or date cutoff.
            $eligible->where('order_kind', 'kol_fulfillment')
                ->orWhere(function (Builder $customer): void {
                    // Pending allocation is based on eligibility, never an order-date cutoff.
                    $customer->where('order_kind', 'customer')
                        ->where(function (Builder $sources): void {
                            $sources->where(function (Builder $woo): void {
                                $woo->where('source_type', 'woocommerce')
                                    ->whereHas('financials', fn (Builder $financial) => $financial->where('provider_order_status', 'processing'));
                            })->orWhereIn('source_type', ['manual', 'manual_other']);
                        });
                });
        });
    }

    public function allows(Order $order): bool
    {
        return $this->constrain(Order::query())->whereKey($order->getKey())->exists();
    }

    public function assertAllowed(Order $order): void
    {
        abort_unless($this->allows($order), 409, '订单未达到待分仓准入条件。');
    }
}
