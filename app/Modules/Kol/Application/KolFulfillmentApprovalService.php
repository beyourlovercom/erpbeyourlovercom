<?php

namespace App\Modules\Kol\Application;

use App\Modules\Logistics\Application\PendingAllocationEntryPolicy;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;

final class KolFulfillmentApprovalService
{
    public function __construct(private readonly OrderService $orders) {}

    public function isApproved(Order $order): bool
    {
        return $order->operationalMarkers()
            ->where('marker', PendingAllocationEntryPolicy::KOL_APPROVAL_MARKER)
            ->exists();
    }

    public function canApprove(Order $order, Authenticatable $actor): bool
    {
        if ($order->order_kind !== OrderKind::KolFulfillment
            || ! $actor->can('kol.approvals.manage')
            || $order->archived_at !== null
            || $order->order_status?->value !== 'pending_shipment'
            || $order->order_date === null
            || $order->order_date->lt(Carbon::parse((string) config('erp_fulfillment.allocation_eligible_from', '2026-11-04')))
            || $this->isApproved($order)
            || $order->fulfillmentAllocation()->where('status', 'submitted')->exists()) {
            return false;
        }

        if ($actor->can('system.permissions.manage')) {
            return true;
        }

        return $order->kol_id !== null
            && (int) ($order->kol?->approval_manager_id ?? 0) === (int) $actor->getAuthIdentifier();
    }

    public function approve(Order $order, Authenticatable $actor): void
    {
        abort_unless($this->canApprove($order, $actor), 403, '仅可由该网红的主管或管理员审核未分仓发货订单。');
        $this->orders->setOperationalMarker($order, PendingAllocationEntryPolicy::KOL_APPROVAL_MARKER, true, $actor);
    }
}
