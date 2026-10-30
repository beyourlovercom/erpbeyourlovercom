<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsOrderGateway;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsReshipment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class XlwmsManualDraftBindingService
{
    public function __construct(private readonly XlwmsOrderGateway $gateway) {}

    public function bind(int $orderId, string $requestId, string $omsOrderNo, int $actorId): array
    {
        if ($actorId <= 0 || config('xlwms.us_sm_outbound_entry_mode') !== 'oms_manual_draft') {
            throw new RuntimeException('OMS_MANUAL_DRAFT_MODE_REQUIRED');
        }
        $r=XlwmsReshipment::query()->where('original_order_id',$orderId)
            ->where('request_id',$requestId)->firstOrFail();
        if ($r->status==='draft_created' && $r->oms_order_no===$omsOrderNo) {
            return ['result'=>'already_bound','oms_order_no'=>$omsOrderNo,'status'=>'draft_created'];
        }
        if ($r->status!=='approved_for_reissue' || filled($r->oms_order_no)
            || (int)$r->approved_by<1) throw new RuntimeException('OMS_MANUAL_DRAFT_APPLICATION_NOT_READY');
        if (XlwmsReshipment::query()->where('oms_order_no',$omsOrderNo)->exists()) {
            throw new RuntimeException('OMS_MANUAL_DRAFT_ALREADY_BOUND');
        }

        $connection=WarehouseOpenApiConnection::query()
            ->where('warehouse_id',$r->warehouse_id)->where('provider','xlwms')->firstOrFail();
        $status=$this->gateway->readExactOrderStatus($connection,$omsOrderNo);
        if (!$status || $status['status_code']!=='0' || filled($status['tracking_no'])
            || $status['platform_order_no']!==$r->third_order_no
            || $status['refer_order_no']!==$r->third_order_no
            || !in_array($status['third_order_no'],['',$r->third_order_no],true)) {
            throw new RuntimeException('OMS_MANUAL_DRAFT_IDENTITY_OR_STATE_MISMATCH');
        }

        $detail=$this->gateway->readManualDraftDetail($connection,$r->third_order_no);
        $items=$detail['productList']??null;
        $expected=(array)$r->sku_quantity_snapshot;
        $actual=$this->canonicalItems($items);
        $wanted=$this->canonicalItems($expected);
        if (($detail['outboundOrderNo']??null)!==$omsOrderNo
            || ($detail['whCode']??null)!==$connection->oms_warehouse_code
            || ($detail['logisticsChannel']??null)!=='Upload_Shipping_Label'
            || (string)($detail['status']??'')!=='0'
            || $actual===null || $wanted===null || $actual!==$wanted) {
            throw new RuntimeException('OMS_MANUAL_DRAFT_DETAIL_MISMATCH');
        }

        DB::transaction(function () use($r,$omsOrderNo,$actorId): void {
            $locked=XlwmsReshipment::query()->whereKey($r->id)->lockForUpdate()->firstOrFail();
            if ($locked->status!=='approved_for_reissue' || filled($locked->oms_order_no)) {
                throw new RuntimeException('OMS_MANUAL_DRAFT_ALREADY_BOUND');
            }
            $locked->forceFill(['status'=>'draft_created','oms_order_no'=>$omsOrderNo,
                'oms_status_code'=>'0','oms_tracking_no'=>null,
                'oms_status_checked_at'=>now(),'last_error_code'=>null])->save();
            DB::table('xlwms_reshipment_events')->insert([
                'reshipment_id'=>$locked->id,'previous_status'=>'approved_for_reissue',
                'status'=>'draft_created','event_code'=>'manual_draft_bound',
                'actor_id'=>$actorId,'oms_order_no'=>$omsOrderNo,'created_at'=>now(),
            ]);
        });
        return ['result'=>'bound','oms_order_no'=>$omsOrderNo,'status'=>'draft_created'];
    }

    /** @return array<string,int>|null */
    private function canonicalItems(mixed $list): ?array
    {
        if (!is_array($list) || $list===[]) return null;
        $result=[];
        foreach ($list as $line) {
            if (!is_array($line)) return null;
            $sku=trim((string)($line['sku']??''));
            $qty=$line['quantity']??null;
            if ($sku==='' || !is_numeric($qty) || (int)$qty<1 || (int)$qty!=$qty) return null;
            $result[$sku]=($result[$sku]??0)+(int)$qty;
        }
        ksort($result);
        return $result;
    }
}
