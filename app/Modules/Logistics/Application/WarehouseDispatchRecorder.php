<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Logistics\Infrastructure\Models\WarehouseDispatchState;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\DB;

/** Audited write-through only AFTER a verified provider order identity. */
final class WarehouseDispatchRecorder
{
    public function recordConfirmed(Order $order, XlwmsDispatchAttempt $attempt): void
    {
        $order->loadMissing('shipment');
        $shipment = $order->shipment;
        if (!$shipment || (int)$attempt->order_id !== (int)$order->id
            || (int)$attempt->warehouse_id !== (int)$shipment->warehouse_id) {
            throw new \DomainException('Warehouse fulfillment identity mismatch.');
        }
        $projection = WarehouseDispatchStatus::from((string)$shipment->shipment_status, $attempt);
        if (!in_array($projection['status'], ['dispatched', 'warehouse_outbound_pending_sync', 'exception', 'shipped'], true)
            || blank($projection['oms_order_no'])) return;

        DB::transaction(function () use ($order, $shipment, $attempt, $projection): void {
            $state = WarehouseDispatchState::query()->where('shipment_id', $shipment->id)->lockForUpdate()->first();
            if ($state && ((int)$state->order_id !== (int)$order->id
                || (int)$state->warehouse_id !== (int)$shipment->warehouse_id
                || ($state->provider_order_no !== null && $state->provider_order_no !== $projection['oms_order_no']))) {
                throw new \DomainException('Warehouse fulfillment evidence conflicts with saved identity.');
            }
            $previous = $state?->status;
            $changed = !$state || $previous !== $projection['status']
                || $state->provider_order_no !== $projection['oms_order_no'];
            $state ??= new WarehouseDispatchState(['shipment_id' => $shipment->id]);
            $state->forceFill([
                'order_id' => $order->id, 'warehouse_id' => $shipment->warehouse_id,
                'provider' => 'xlwms', 'status' => $projection['status'],
                'provider_order_no' => $projection['oms_order_no'],
                'provider_status_code' => $attempt->oms_status_code,
                'failure_code' => $attempt->oms_status_failure_code,
                'last_checked_at' => $attempt->oms_status_checked_at ?? $attempt->last_checked_at,
            ])->save();
            if ($changed) DB::table('warehouse_dispatch_events')->insert([
                'shipment_id' => $shipment->id, 'provider' => 'xlwms',
                'previous_status' => $previous, 'status' => $projection['status'],
                'provider_order_no' => $projection['oms_order_no'],
                'event_code' => (string)$attempt->state,
                'created_at' => now(),
            ]);
        });
    }
}
