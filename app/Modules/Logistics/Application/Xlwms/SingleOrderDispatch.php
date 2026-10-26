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
            'duplicate_match_field'=>$attempt->duplicate_match_field,
            'last_checked_at'=>$attempt->last_checked_at,
            'oms_status_code'=>$attempt->oms_status_code,
            'oms_status_checked_at'=>$attempt->oms_status_checked_at,
            'oms_status_failure_code'=>$attempt->oms_status_failure_code,
            'oms_tracking_no'=>$attempt->oms_tracking_no,
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
            $already=$this->oms->findExisting(
                $connection, $preview['oms_reference'], $preview['payload']['platformOrderNo'],
                [$preview['payload']['referOrderNo'], (string) $order->external_order_number]
            );
            if ($already !== []) {
                $attempt->forceFill([
                    'state'=>'blocked_existing_oms_order',
                    'oms_order_no'=>(string)($already[0]['outboundOrderNo']??$already[0]['orderNo']??'')?:null,
                    'duplicate_match_field'=>$already[0]['_erp_match_field']??null,
                    'failure_code'=>'OMS_DUPLICATE_ORDER',
                    'last_checked_at'=>now(),
                ])->save();
                return $this->report($attempt);
            }

            // Read-only remote product/channel validation must succeed before
            // the one and only outbound create request.
            $this->oms->assertCatalogReady($connection,
                $preview['payload']['whCode'],
                $preview['payload']['logisticsChannel'],
                array_column($preview['payload']['productList'], 'sku')
            );
            $attempt->forceFill(['state'=>'request_in_flight','last_checked_at'=>now()])->save();
            $created=$this->oms->createSingle($connection,$preview['payload']);
            $attempt->forceFill([
                'state'=>'submitted_oms',
                'oms_order_no'=>$created['order_no'],
                'last_checked_at'=>now(),
                'failure_code'=>null,
            ])->save();
            return $this->report($attempt);
        } catch (\Throwable $e) {
            // Never retry automatically. A timeout/500 can occur AFTER OMS
            // accepted the request. Leave a durable state for manual detail check.
            $code = in_array($e->getMessage(), [
                'OMS_TRANSPORT_UNCERTAIN','OMS_SERVER_UNCERTAIN','OMS_HTTP_REJECTED',
                'OMS_BUSINESS_REJECTED','OMS_CREATE_NOT_CONFIRMED',
                'OMS_DETAIL_RESPONSE_INVALID', 'OMS_CATALOG_INPUT_INVALID',
                'OMS_CHANNEL_NOT_AVAILABLE', 'OMS_PRODUCT_CATALOG_UNAVAILABLE',
                'OMS_SKU_NOT_APPROVED_OR_MISSING',
                'OMS_DUPLICATE_SCAN_INCOMPLETE', 'OMS_PAGE_RESPONSE_INVALID',
                'OMS_DUPLICATE_LOOKUP_INPUT_INVALID',
            ], true) ? $e->getMessage() : 'OMS_UNEXPECTED_ERROR';
            $isUncertain = $attempt->state === 'request_in_flight';
            // Read-only/validation errors BEFORE create are blocked, not
            // ambiguous creation results. Neither state allows blind retry.
            $attempt->forceFill([
                'state'=>$isUncertain?'uncertain_manual_reconcile':'blocked_preflight_error',
                'failure_code'=>$code,'last_checked_at'=>now(),
            ])->save();
            return $this->report($attempt);
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
        $existing=$this->oms->findExisting(
            $connection,$attempt->third_order_no,substr($platformNo,0,50),
            [(string)$order->erp_order_id,(string)$order->external_order_number]
        );
        if($existing !== []){
            $attempt->forceFill([
                'state'=>'found_in_oms',
                'oms_order_no'=>(string)($existing[0]['outboundOrderNo']??$existing[0]['orderNo']??'')?:null,
                'duplicate_match_field'=>$existing[0]['_erp_match_field']??null,
                'failure_code'=>null,
                'last_checked_at'=>now(),
            ])->save();
        }
        if ($existing === []) {
            $attempt->forceFill(['last_checked_at'=>now()])->save();
        }
        return $this->report($attempt) + ['remote_found'=>$existing!==[]];
    }

    /**
     * Manual, read-only OMS status refresh for a SINGLE previously identified
     * OMS order. Neither Shipment, Order nor Woo status is modified.
     * Unknown provider status codes are shown literally, never auto-shipped.
     *
     * @return array<string,mixed>
     */
    public function checkStatus(int $orderId): array
    {
        $attempt = XlwmsDispatchAttempt::query()->where('order_id', $orderId)->firstOrFail();
        $order = Order::query()->with('shipment')->findOrFail($orderId);
        $omsNo = trim((string)$attempt->oms_order_no);
        if ($omsNo === '' || ! $order->shipment
            || (int) $attempt->warehouse_id !== (int)$order->shipment->warehouse_id) {
            throw new \RuntimeException('OMS_STATUS_NOT_READY');
        }
        $connection = WarehouseOpenApiConnection::query()
            ->where('warehouse_id', $attempt->warehouse_id)
            ->where('provider', 'xlwms')->firstOrFail();

        try {
            $remote = $this->oms->readExactOrderStatus($connection, $omsNo);
            if ($remote === null) {
                throw new \RuntimeException('OMS_STATUS_NOT_FOUND');
            }
            // A linked OMS order must still carry the exact ERP identifiers.
            // Previously manually-created OMS orders can have a different
            // thirdOrderNo, but must match an ERP reference/platform number.
            $refs = array_values(array_unique(array_filter([
                (string)$order->erp_order_id,
                (string)$order->external_order_number,
            ], fn($s) => trim($s) !== '')));
            $identityMatches =
                $remote['third_order_no'] === $attempt->third_order_no
                || in_array($remote['refer_order_no'], $refs, true)
                || in_array($remote['platform_order_no'], $refs, true);
            if (! $identityMatches) {
                throw new \RuntimeException('OMS_STATUS_IDENTITY_MISMATCH');
            }
            $attempt->forceFill([
                'oms_status_code' => $remote['status_code'],
                'oms_status_checked_at' => now(),
                'oms_tracking_no' => $remote['tracking_no'],
                'oms_status_failure_code' => null,
                'last_checked_at' => now(),
            ])->save();
            return $this->statusReport($attempt, true);
        } catch (\Throwable $e) {
            // Preserve the last confirmed status instead of replacing it
            // with potentially invalid or ambiguous data. Never retry create.
            $safeCodes = [
                'OMS_STATUS_QUERY_INVALID','OMS_STATUS_NOT_FOUND',
                'OMS_STATUS_RESPONSE_INVALID','OMS_STATUS_IDENTITY_MISMATCH',
                'OMS_TRANSPORT_UNCERTAIN','OMS_HTTP_REJECTED',
                'OMS_SERVER_UNCERTAIN','OMS_BUSINESS_REJECTED',
            ];
            $code = in_array($e->getMessage(), $safeCodes, true)
                ? $e->getMessage() : 'OMS_STATUS_UNEXPECTED_ERROR';
            $attempt->forceFill(['oms_status_failure_code'=>$code])->save();
            return $this->statusReport($attempt, false);
        }
    }

    /** @return array<string,mixed> */
    private function statusReport(XlwmsDispatchAttempt $attempt, bool $fresh): array
    {
        // Only 3 and 4 are verified against the real OMS account:
        // 3 = 已出库, 4 = 已取消. Never guess the other codes.
        $labels = ['3'=>'已出库','4'=>'已取消'];
        $status = $attempt->oms_status_code;
        return [
            'read_only' => true,
            'fresh' => $fresh,
            'oms_order_no' => $attempt->oms_order_no,
            'oms_status_code' => $status,
            'oms_status_label' => $status === null ? '尚未查询'
                : ($labels[$status] ?? ('OMS 状态 '.$status.'（待核实）')),
            'oms_tracking_no' => $attempt->oms_tracking_no,
            'oms_status_checked_at' => $attempt->oms_status_checked_at,
            'oms_status_failure_code' => $attempt->oms_status_failure_code,
            'erp_status_unchanged' => true,
            'woo_status_unchanged' => true,
        ];
    }

    /** Stable, redacted response for ERP status feedback; never log addresses/keys. */
    private function report(XlwmsDispatchAttempt $attempt): array
    {
        $labels = [
            'blocked_existing_oms_order'=>'OMS 已存在订单，禁止重复发货',
            'blocked_preflight_error'=>'发送前检查失败，禁止提交',
            'request_in_flight'=>'请求提交中，结果尚未确认',
            'uncertain_manual_reconcile'=>'OMS 请求结果未知，先查单，不可直接重发',
            'submitted_oms'=>'OMS 已接收订单（不代表已出库）',
            'found_in_oms'=>'已在 OMS 查到对应订单',
            'checking_existing'=>'正在检查 OMS 是否已有订单',
        ];
        return [
            'state'=>$attempt->state,
            'status_label'=>$labels[$attempt->state]??'等待处理',
            'oms_order_no'=>$attempt->oms_order_no,
            'failure_code'=>$attempt->failure_code,
            'duplicate_match_field'=>$attempt->duplicate_match_field,
            'last_checked_at'=>$attempt->last_checked_at,
        ];
    }
}
