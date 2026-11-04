<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\Eloquent\Builder;

final class PendingAllocationEntryPolicy
{
    public const KOL_APPROVAL_MARKER = 'kol_fulfillment_approved';

    /** Retain every Woo status in ERP. Only determine who enters the allocation queue. */
    public function constrain(Builder $query): Builder
    {
        return $query
            ->where('order_date', '>=', (string) config('erp_fulfillment.allocation_eligible_from', '2026-11-04'))
            ->where(function (Builder $eligible): void {
                $eligible->where(function (Builder $woo): void {
                    $woo->where('order_kind', 'customer')
                        ->where('source_type', 'woocommerce')
                        ->whereHas('financials', fn (Builder $f) => $f->where('provider_order_status', 'processing'));
                })->orWhere(function (Builder $kol): void {
                    // Only an approval of this specific fulfillment order counts.
                    // KOL account/collaboration approval is NOT shipping authorization.
                    $kol->where('order_kind', 'kol_fulfillment')
                        ->whereHas('operationalMarkers', fn (Builder $m) => $m->where('marker', self::KOL_APPROVAL_MARKER));
                })->orWhere(function (Builder $manual): void {
                    // Staff-created customer orders have no WooCommerce payment status.
                    $manual->where('order_kind', 'customer')->whereIn('source_type', ['manual', 'manual_other']);
                });
            });
    }

    public function allows(Order $order): bool
    {
        return $this->constrain(Order::query())->whereKey($order->getKey())->exists();
    }

    public function assertAllowed(Order $order): void
    {
        abort_unless($this->allows($order), 409, '订单未满足待分仓门槛：订单日期、Woo processing 或网红主管审核。');
    }
}
