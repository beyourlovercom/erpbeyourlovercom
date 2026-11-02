<?php

namespace App\Modules\Orders\Application;

use App\Modules\Orders\Infrastructure\Models\Order;

final class OrderWorkflowStatusProjection
{
    /** @return array{code:string,en:string,zh:string,color:string,source:string,mutable:bool} */
    public function for(Order $order): array
    {
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
