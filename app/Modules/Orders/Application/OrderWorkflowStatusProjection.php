<?php

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Infrastructure\Models\Order;

final class OrderWorkflowStatusProjection
{
    public function __construct(private readonly OrderProgressResolver $progress, private readonly OrderAuxiliaryStatusService $auxiliary) {}
    /** @return array{code:string,en:string,zh:string,color:string,source:string,mutable:bool} */
    public function for(Order $order): array
    {
        $phase = $this->progress->resolve($order);
        // Customer orders display the authority-derived progress. Old staff
        // follow-up codes become secondary markers; KOL business phases stay as before.
        $customer = $phase !== null;
        $selected = $customer ? OrderManualHoldStatus::selected($order) : null;
        $code = $customer
            ? (($selected && OrderManualHoldStatus::bucket($selected) === $phase) ? $selected : $phase)
            : OrderWorkflowStatus::effectiveCode($order->workflow_status, $order->order_status->value);
        $definition = OrderWorkflowStatus::definition($code) ?? OrderWorkflowStatus::registry()['sending'];

        return [
            'code' => $code,
            'en' => $definition['en'],
            'zh' => $definition['zh'],
            'color' => $definition['color'],
            'source' => $code === 'progress_conflict' ? 'reconciliation_required'
                : ($customer ? 'canonical_projection' : 'staff_workflow'),
            'mutable' => $definition['mutable'] && ! $customer,
            // Customer dropdown values are mutually exclusive primary states,
            // never a row of persisted badges. Legacy tags remain auditable.
            'auxiliary' => [],
        ];
    }
}
