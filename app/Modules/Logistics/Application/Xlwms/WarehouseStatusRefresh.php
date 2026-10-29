<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;
use App\Modules\Logistics\Application\WarehouseDispatchRecorder;
use App\Modules\Orders\Infrastructure\Models\Order;

/** Read OMS status only; caches evidence. Never issues outbound CREATE or ships ERP/Woo. */
final class WarehouseStatusRefresh
{
    public function __construct(
        private readonly SingleOrderDispatch $single,
        private readonly WarehouseDispatchRecorder $recorder,
    ) {}

    public function run(): array
    {
        $warehouse = Warehouse::query()->where('code', 'US-SM')->first();
        if (!$warehouse) return ['count' => 0, 'refreshed' => 0, 'failed' => 0, 'results' => []];
        // Each row is a known OMS order; never query new/failed dispatch attempts.
        $attempts = XlwmsDispatchAttempt::query()->where('warehouse_id', $warehouse->id)
            ->whereNotNull('oms_order_no')->where('oms_order_no', '!=', '')
            ->orderBy('oms_status_checked_at')->orderBy('id')->limit(10)->get();
        $results = [];
        foreach ($attempts as $attempt) {
            $order = Order::query()->with('shipment')->find($attempt->order_id);
            if (!$order || !$order->shipment || (int)$order->shipment->warehouse_id !== (int)$warehouse->id) {
                $results[] = ['order_id' => (int)$attempt->order_id, 'code' => 'identity_mismatch'];
                continue;
            }
            try {
                $status = $this->single->checkStatus((int)$order->id);
                if ($status['fresh'] ?? false) {
                    $this->recorder->recordConfirmed($order, $attempt->fresh());
                }
                $results[] = ['order_id' => (int)$order->id,
                    'code' => ($status['fresh'] ?? false) ? 'refreshed' : 'failed'];
            } catch (\Throwable) {
                $results[] = ['order_id' => (int)$order->id, 'code' => 'failed'];
            }
        }
        return [
            'count' => count($results),
            'refreshed' => count(array_filter($results, fn($r) => $r['code'] === 'refreshed')),
            'failed' => count(array_filter($results, fn($r) => $r['code'] !== 'refreshed')),
            'results' => $results,
        ];
    }
}
