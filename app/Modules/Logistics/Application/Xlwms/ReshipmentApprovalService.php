<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsReshipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Second operator confirmation, strictly without OMS CREATE. */
final class ReshipmentApprovalService
{
    public function __construct(private readonly ReshipmentPreview $preview) {}
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
            $shipped=in_array($order->order_status?->value,['shipped','completed','completed_delivered'],true)
                && $shipment?->shipment_status==='shipped';
            $cancelledOriginal=false;
            if (!$shipped && $order->order_status?->value==='pending_shipment'
                && $shipment?->shipment_status==='pending') {
                $preview=$this->preview->forOrder($orderId,$requestId);
                $cancelledOriginal=$preview['ready_for_review']===true
                    && $preview['source_scenario']==='canceled_original_oms'
                    && $preview['original_oms_order_no']===$record->original_oms_order_no;
            }
            if ((!$shipped && !$cancelledOriginal)
                || (int) $shipment->id !== (int) $record->original_shipment_id
                || (int) $shipment->warehouse_id !== (int) $record->warehouse_id
                || $shipment->warehouse?->code !== 'US-SM'
                || (! filled($shipment->tracking_number) && ! filled($record->original_oms_order_no))
                || $record->memo !== '重新下发仓库'
                || ! str_starts_with($record->third_order_no, 'BYL-RESHIP-')
                || filled($record->oms_order_no)) {
                throw new RuntimeException('RESHIP_APPROVAL_SOURCE_CHANGED');
            }
            $record->forceFill(['status' => 'approved_for_reissue', 'approved_by' => $actorId])->save();
            DB::table('xlwms_reshipment_events')->insert([
                'reshipment_id' => $record->id,
                'previous_status' => 'requested',
                'status' => 'approved_for_reissue',
                'event_code' => 'operator_approved_reissue',
                'actor_id' => $actorId,
                'created_at' => now(),
            ]);
            return ['request_id' => $record->request_id,
                'status' => 'approved_for_reissue',
                'can_create_oms' => (bool) config('xlwms.reissue_create_enabled',false)
                    && (int) config('xlwms.reissue_order_id',0) === $orderId
                    && hash_equals((string) config('xlwms.reissue_request_id',''),$requestId)];
        });
    }
}
