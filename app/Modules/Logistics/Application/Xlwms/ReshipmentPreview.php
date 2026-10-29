<?php
namespace App\Modules\Logistics\Application\Xlwms;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsReshipment;
use Illuminate\Support\Facades\Schema;
/** Deliberately read-only. Never retry original order's external CREATE. */
final class ReshipmentPreview {
    public function __construct(private readonly SingleOrderPreview $regular) {}
    public function forOrder(int $id, ?string $currentRequestId = null, bool $requireFreshStock = false): array {
        $order=Order::with(['shipment.warehouse','shipment.overseaReview','address','items'])->findOrFail($id);
        $ship=$order->shipment;
        $old=XlwmsDispatchAttempt::query()->where('order_id',$id)->first();
        $base=$this->regular->forOrder($order,$requireFreshStock);
        $shipped=in_array($order->order_status?->value, ['shipped','completed','completed_delivered'], true)
            && $ship?->shipment_status==='shipped';
        $exceptions=['order_not_pending_shipment','shipment_not_pending','tracking_already_assigned'];
        $blocked=array_values(array_filter($base['blockers'],
            fn($reason)=>!($shipped&&in_array($reason,$exceptions,true))));
        if(!$shipped)$blocked[]='source_order_not_shipped';
        if(!filled($ship?->tracking_number)&&!filled($old?->oms_order_no))$blocked[]='original_shipping_evidence_missing';
        // A new provider draft requires a verifiable native original OMS identity.
        // Tracking alone proves shipment, not that the old OMS order is safe.
        if(!filled($old?->oms_order_no))$blocked[]='original_oms_identity_missing';
        if(($base['payload']['logisticsChannel']??null)!=='No_Shipping_Service'
            ||($base['payload']['isSubmit']??null)!==0)$blocked[]='reship_draft_contract_invalid';
        $prior=Schema::hasTable('xlwms_reshipments')
            ? XlwmsReshipment::query()->where('original_order_id',$id)
                ->when($currentRequestId, fn($q)=>$q->where('request_id','!=',$currentRequestId))
                ->whereNotIn('status',['rejected','canceled'])->count() : 0;
        if($prior>0)$blocked[]='existing_reshipment_requires_review';
        $latest=XlwmsReshipment::query()->where('original_order_id',$id)
            ->whereNotIn('status',['rejected','canceled'])->latest('id')->first();
        $existing=$latest ? [
            'request_id'=>$latest->request_id,
            'status'=>$latest->status,
            'oms_order_no'=>$latest->oms_order_no,
            'oms_status_code'=>$latest->oms_status_code,
            'oms_tracking_no'=>$latest->oms_tracking_no,
            'last_error_code'=>$latest->last_error_code,
            'can_create_oms'=>$latest->status==='approved_for_reissue'
                && (bool)config('xlwms.reissue_create_enabled',false)
                && (int)config('xlwms.reissue_order_id',0)===$id
                && hash_equals((string)config('xlwms.reissue_request_id',''),$latest->request_id),
        ] : null;
        $address=$order->address;
        return [
            'order_id'=>$id,'erp_order_id'=>$order->erp_order_id,
            'original_oms_order_no'=>$old?->oms_order_no,
            'original_tracking_present'=>filled($ship?->tracking_number),
            'warehouse'=>$ship?->warehouse?->code,
            'channel'=>'No_Shipping_Service','draft_only'=>true,
            'items'=>$base['payload']['productList'],
            'recipient'=>$address?->recipient_name,
            'address'=>[
                'country'=>$address?->country,
                'province'=>$address?->province_state,
                'city'=>$address?->city,
                'postal_code'=>$address?->postal_code,
                'address_1'=>$address?->address_line_1,
            ],
            'reason_default'=>'仓库订单出错','required_oms_memo'=>null,
            'existing_reshipment_count'=>$prior,
            'existing_reissue'=>$existing,
            'blockers'=>array_values(array_unique($blocked)),
            'ready_for_review'=>$blocked===[],
            'can_create_oms'=>false,
            'memo_contract_verified'=>false,
        ];
    }
}
