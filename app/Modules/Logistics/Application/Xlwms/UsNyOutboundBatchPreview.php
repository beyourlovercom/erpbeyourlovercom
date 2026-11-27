<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;
use App\Modules\Orders\Infrastructure\Models\Order;
use RuntimeException;

/** ERP1 US-NY selected-order preview. No provider or database write. */
final class UsNyOutboundBatchPreview
{
    public function __construct(private readonly SingleOrderPreview $preview) {}

    public function preview(array $orderIds): array
    {
        if ($orderIds === [] || count($orderIds) > 200
            || count(array_unique($orderIds)) !== count($orderIds)) {
            throw new RuntimeException('US_NY_SELECTION_INVALID');
        }
        foreach ($orderIds as $id) {
            if (! is_int($id) || $id < 1) throw new RuntimeException('US_NY_SELECTION_INVALID');
        }
        sort($orderIds, SORT_NUMERIC);
        $orders = Order::query()->eligibleForOperations()->with([
            'address', 'items', 'shipment.warehouse', 'shipment.carrierService', 'shipment.overseaReview',
        ])->whereIn('id', $orderIds)->get()->keyBy('id');
        $attempts = XlwmsDispatchAttempt::query()->whereIn('order_id', $orderIds)->pluck('order_id')->all();
        $rows = [];
        foreach ($orderIds as $id) {
            $order = $orders->get($id);
            $blockers = [];
            $hash = null;
            if (! $order || $order->shipment?->warehouse?->code !== 'US-NY'
                || $order->routing_warehouse_code !== 'US-NY') {
                $blockers[] = 'US_NY_ORDER_WRONG_WAREHOUSE';
            } else {
                if ($order->workflow_status !== 'sending') $blockers[] = 'US_NY_ORDER_NOT_REVIEWED_FOR_DISPATCH';
                if (in_array($id, $attempts, true)) $blockers[] = 'US_NY_EXISTING_PROVIDER_ATTEMPT';
                $check = $this->preview->forOrder($order, true, false, 'US-NY');
                $blockers = array_values(array_unique([...$blockers, ...$check['blockers']]));
                $hash = $check['payload_hash'];
            }
            $rows[] = ['order_id' => $id, 'order_no' => (string) ($order?->erp_order_id ?: $id),
                'ready' => $blockers === [], 'blockers' => $blockers, 'payload_hash' => $hash];
        }
        return ['warehouse' => 'US-NY', 'count' => count(array_filter($rows, fn ($r) => $r['ready'])),
            'items' => $rows, 'read_only' => true, 'fingerprint' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
    }
}
