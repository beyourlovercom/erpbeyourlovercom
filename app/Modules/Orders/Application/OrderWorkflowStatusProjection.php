<?php

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Infrastructure\Models\Order;

final class OrderWorkflowStatusProjection
{
    /** @return array{code:string,en:string,zh:string,color:string,source:string,mutable:bool} */
    public function for(Order $order): array
    {
        // Once Vita submits allocation the source order becomes a bookkeeping
        // root. Never misrepresent that as a shipped fulfillment.
        if ($order->fulfillment_parent_id === null
            && $order->order_status->value === 'pending_shipment'
            && in_array($order->workflow_status, [null, 'sending'], true)
            && $order->fulfillmentAllocation?->status === 'submitted') {
            return ['code' => 'allocated', 'en' => 'Allocated', 'zh' => '已分仓',
                'color' => 'blue', 'source' => 'canonical_projection', 'mutable' => false];
        }

        $code = OrderWorkflowStatus::effectiveCode($order->workflow_status, $order->order_status->value);
        $definition = OrderWorkflowStatus::definition($code) ?? OrderWorkflowStatus::registry()['sending'];

        return [
            'code' => $code,
            'en' => $definition['en'],
            'zh' => $definition['zh'],
            'color' => $definition['color'],
            'source' => ($order->workflow_status === null || $code !== $order->workflow_status || OrderWorkflowStatus::isCanonicalCode($code)) ? 'canonical_projection' : 'staff_workflow',
            'mutable' => $definition['mutable'],
        ];
    }
}
