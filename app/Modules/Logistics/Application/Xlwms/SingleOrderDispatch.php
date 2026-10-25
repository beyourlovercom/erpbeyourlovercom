<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsOrderGateway;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\DB;

final class SingleOrderDispatch
{
    public function __construct(
        private readonly SingleOrderPreview $preview,
        private readonly XlwmsOrderGateway $oms,
    ) {}

    /** @return array<string,mixed> */
    public function preview(int $orderId): array
    {
        $order=Order::query()->with([
            'address','items','shipment.warehouse','shipment.carrierService','shipment.overseaReview'
        ])->findOrFail($orderId);
        $data=$this->preview->forOrder($order);
        $attempt=XlwmsDispatchAttempt::query()->where('order_id',$orderId)->first();
        $data['attempt']=$attempt ? [
            'state'=>$attempt->state,
            'oms_order_no'=>$attempt->oms_order_no,
            'failure_code'=>$attempt->failure_code,
        ]:null;
        if($attempt) {
            $data['ready_for_remote_check']=false;
            $data['blockers'][]='existing_dispatch_attempt';
        }
        return $data;
    }

    /** @return array{state:string,oms_order_no:?string} */
    public function dispatch(int $orderId, string $expectedHash, int $actorId): array
    {
        abort_unless((bool) config('xlwms.live_dispatch_enabled', false)
            && (int) config('xlwms.live_order_id', 0) > 0
            && (int) config('xlwms.live_order_id', 0) === $orderId,
            423, '当前具体订单尚未获得单笔真实下发授权');
        $preview=$this->preview($orderId);
        abort_unless($preview['ready_for_remote_check'],409,'订单预检未通过或已有发货尝试');
        abort_unless(hash_equals($preview['payload_hash'],$expectedHash),409,'订单字段已更改，请重新预览');
        $order=Order::query()->with('shipment')->findOrFail($orderId);
        $connection=WarehouseOpenApiConnection::query()
            ->where('warehouse_id',$order->shipment->warehouse_id)->where('provider','xlwms')->firstOrFail();

        // Unique(order_id) and unique(third_order_no) forbid a second dispatch
        // even when two simultaneous HTTP handlers race.
        $attempt=DB::transaction(function() use($order,$preview,$actorId): XlwmsDispatchAttempt {
            abort_if(XlwmsDispatchAttempt::query()->where('order_id',$order->id)->lockForUpdate()->exists(),409);
            return XlwmsDispatchAttempt::query()->create([
                'order_id'=>$order->id,
                'warehouse_id'=>$order->shipment->warehouse_id,
                'third_order_no'=>$preview['oms_reference'],
                'state'=>'checking_existing',
                'request_fingerprint'=>$preview['payload_hash'],
                'requested_by'=>$actorId,
            ]);
        });

        try {
            // Also check Woo-origin OMS orders that have the same platformOrderNo
            // but were previously imported with a different thirdOrderNo.
            $already=$this->oms->findExisting($connection, $preview['oms_reference'],
                $preview['payload']['platformOrderNo']);
            if ($already !== []) {
                $attempt->forceFill([
                    'state'=>'blocked_existing_oms_order',
                    'oms_order_no'=>(string)($already[0]['outboundOrderNo']??'')?:null,
                ])->save();
                return ['state'=>'blocked_existing_oms_order','oms_order_no'=>$attempt->oms_order_no];
            }

            // Read-only remote product/channel validation must succeed before
            // the one and only outbound create request.
            $this->oms->assertCatalogReady($connection,
                $preview['payload']['whCode'],
                $preview['payload']['logisticsChannel'],
                array_column($preview['payload']['productList'], 'sku')
            );
            $attempt->forceFill(['state'=>'request_in_flight'])->save();
            $created=$this->oms->createSingle($connection,$preview['payload']);
            $attempt->forceFill([
                'state'=>'submitted_oms',
                'oms_order_no'=>$created['order_no'],
            ])->save();
            return ['state'=>'submitted_oms','oms_order_no'=>$created['order_no']];
        } catch (\Throwable $e) {
            // Never retry automatically. A timeout/500 can occur AFTER OMS
            // accepted the request. Leave a durable state for manual detail check.
            $code = in_array($e->getMessage(), [
                'OMS_TRANSPORT_UNCERTAIN','OMS_SERVER_UNCERTAIN','OMS_HTTP_REJECTED',
                'OMS_BUSINESS_REJECTED','OMS_CREATE_NOT_CONFIRMED',
                'OMS_DETAIL_RESPONSE_INVALID', 'OMS_CATALOG_INPUT_INVALID',
                'OMS_CHANNEL_NOT_AVAILABLE', 'OMS_PRODUCT_CATALOG_UNAVAILABLE',
                'OMS_SKU_NOT_APPROVED_OR_MISSING',
            ], true) ? $e->getMessage() : 'OMS_UNEXPECTED_ERROR';
            $attempt->forceFill(['state'=>'uncertain_manual_reconcile','failure_code'=>$code])->save();
            return ['state'=>'uncertain_manual_reconcile','oms_order_no'=>null];
        }
    }

    /** Read-only reconciliation; NEVER auto retries create(). */
    public function reconcile(int $orderId): array
    {
        $attempt=XlwmsDispatchAttempt::query()->where('order_id',$orderId)->firstOrFail();
        $order=Order::query()->with('shipment')->findOrFail($orderId);
        $connection=WarehouseOpenApiConnection::query()
            ->where('warehouse_id',$order->shipment->warehouse_id)->where('provider','xlwms')->firstOrFail();
        $platformNo=(string) ($order->external_order_number ?: $order->erp_order_id ?: $order->id);
        $existing=$this->oms->findExisting($connection,$attempt->third_order_no,substr($platformNo,0,50));
        if($existing !== []){
            $attempt->forceFill([
                'state'=>'found_in_oms',
                'oms_order_no'=>(string)($existing[0]['outboundOrderNo']??'')?:null,
                'failure_code'=>null,
            ])->save();
        }
        return ['state'=>$attempt->state,'oms_order_no'=>$attempt->oms_order_no,
            'remote_found'=>$existing!==[]];
    }
}
