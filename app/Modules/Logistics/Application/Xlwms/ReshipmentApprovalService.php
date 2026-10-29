<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsReshipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Second operator confirmation, strictly without OMS CREATE. */
final class ReshipmentApprovalService
{
    public function approve(int $orderId, string $requestId, int $actorId, string $confirmation): array
    {
        if ($actorId < 1 || ! preg_match('/^[0-9a-f-]{36}$/i', $requestId)
            || ! hash_equals('APPROVE_RESHIP_'.$requestId, $confirmation)) {
            throw new RuntimeException('RESHIP_APPROVAL_CONFIRMATION_INVALID');
        }

        return DB::transaction(function () use ($orderId, $requestId, $actorId): array {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $record = XlwmsReshipment::query()->where('original_order_id', $orderId)
                ->where('request_id', $requestId)->lockForUpdate()->first();
            if (! $record || $record->status !== 'requested') {
                throw new RuntimeException('RESHIP_APPROVAL_STATE_INVALID');
            }
            $shipment = $order->shipment;
            if (! in_array($order->order_status?->value, ['shipped', 'completed', 'completed_delivered'], true)
                || $shipment?->shipment_status !== 'shipped'
                || (int) $shipment->id !== (int) $record->original_shipment_id
                || (int) $shipment->warehouse_id !== (int) $record->warehouse_id
                || $shipment->warehouse?->code !== 'US-SM'
                || (! filled($shipment->tracking_number) && ! filled($record->original_oms_order_no))
                || $record->memo !== '重发订单'
                || ! str_starts_with($record->third_order_no, 'BYL-RESHIP-')
                || filled($record->oms_order_no)) {
                throw new RuntimeException('RESHIP_APPROVAL_SOURCE_CHANGED');
            }
            $record->forceFill(['status' => 'approved_pending_provider_contract', 'approved_by' => $actorId])->save();
            DB::table('xlwms_reshipment_events')->insert([
                'reshipment_id' => $record->id,
                'previous_status' => 'requested',
                'status' => 'approved_pending_provider_contract',
                'event_code' => 'operator_approved_no_create',
                'actor_id' => $actorId,
                'created_at' => now(),
            ]);
            return ['request_id' => $record->request_id,
                'status' => 'approved_pending_provider_contract', 'can_create_oms' => false];
        });
    }
}
