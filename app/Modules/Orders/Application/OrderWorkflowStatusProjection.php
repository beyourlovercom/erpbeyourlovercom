<?php

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Infrastructure\Models\Order;

final class OrderWorkflowStatusProjection
{
    public function __construct(private readonly OrderProgressResolver $progress) {}
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

        $phase = $this->progress->resolve($order);
        // R0-R3 bridge: existing staff annotations are retained until R4
        // migrates them to independent auxiliary badges.
        $legacyAnnotation = $order->workflow_status !== null
            && ! in_array($order->workflow_status, ['sending', 'sent', 'out_of_stock'], true)
            && $phase !== 'progress_conflict';
        $code = $legacyAnnotation
            ? OrderWorkflowStatus::effectiveCode($order->workflow_status, $order->order_status->value)
            : ($phase ?? OrderWorkflowStatus::effectiveCode($order->workflow_status, $order->order_status->value));
        $definition = OrderWorkflowStatus::definition($code) ?? OrderWorkflowStatus::registry()['sending'];

        return [
            'code' => $code,
            'en' => $definition['en'],
            'zh' => $definition['zh'],
            'color' => $definition['color'],
            'source' => $code === 'progress_conflict' ? 'reconciliation_required'
                : ($legacyAnnotation ? 'staff_workflow' : 'canonical_projection'),
            'mutable' => $definition['mutable'],
        ];
    }
}
