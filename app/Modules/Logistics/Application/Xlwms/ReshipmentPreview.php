<?php
namespace App\Modules\Logistics\Application\Xlwms;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsReshipment;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsOrderGateway;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsInventorySnapshot;
use Illuminate\Support\Facades\Schema;
/** Deliberately read-only. Never retry original order's external CREATE. */
final class ReshipmentPreview {
    public function __construct(private readonly SingleOrderPreview $regular, private readonly XlwmsOrderGateway $gateway) {}
    public function forOrder(int $id, ?string $currentRequestId = null, bool $requireFreshStock = false): array {
        $order=Order::with(['shipment.warehouse','shipment.overseaReview','address','items'])->findOrFail($id);
        $ship=$order->shipment;
        $old=XlwmsDispatchAttempt::query()->where('order_id',$id)->first();
        $base=$this->regular->forOrder($order,$requireFreshStock);
        // Only the one exact Owner-approved Canary may use fresh target SKU
        // evidence when unrelated SKUs block full-warehouse reconciliation.
        $targetedStockVerified=$requireFreshStock
            && (in_array('OMS_SNAPSHOT_NOT_FRESH',$base['blockers'],true)
                || in_array('OMS_SNAPSHOT_NOT_RECONCILED',$base['blockers'],true))
            && $this->authorizedTargetSkuStock($order,$currentRequestId);
        if ($targetedStockVerified) {
            $base['blockers']=array_values(array_diff($base['blockers'],['OMS_SNAPSHOT_NOT_FRESH','OMS_SNAPSHOT_NOT_RECONCILED']));
        }
        $shipped=in_array($order->order_status?->value, ['shipped','completed','completed_delivered'], true)
            && $ship?->shipment_status==='shipped';
        // The first OMS order was cancelled before real shipment. Confirm cancellation
        // and native OMS identity LIVE, never from a potentially stale ERP status cache.
        $cancelledOriginal=false;
        if (!$shipped && $order->order_status?->value==='pending_shipment'
            && $ship?->shipment_status==='pending' && $ship?->warehouse?->code==='US-SM'
            && !filled($ship?->tracking_number) && filled($old?->oms_order_no)) {
            $connection=WarehouseOpenApiConnection::query()
                ->where('warehouse_id',$ship->warehouse_id)->where('provider','xlwms')->first();
            if ($connection) {
                try {
                    $remote=$this->gateway->readExactOrderStatus($connection,$old->oms_order_no);
                    $cancelledOriginal=($remote['status_code']??null)==='4'
                        && ($remote['third_order_no']??null)==='BYL-ERP-'.$id
                        && !filled($remote['tracking_no']??null);
                } catch (\Throwable) {
                    $cancelledOriginal=false;
                }
            }
        }
        $exceptions=['order_not_pending_shipment','shipment_not_pending','tracking_already_assigned'];
        $blocked=array_values(array_filter($base['blockers'],
            fn($reason)=>!($shipped&&in_array($reason,$exceptions,true))));
        if(!$shipped && !$cancelledOriginal) {
            $blocked[]='source_order_not_shipped';
            $blocked[]='source_order_not_shipped_or_original_oms_not_canceled';
        }
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
            'source_scenario'=>$cancelledOriginal?'canceled_original_oms':($shipped?'shipped_original':'not_eligible'),
            'original_tracking_present'=>filled($ship?->tracking_number),
            'warehouse'=>$ship?->warehouse?->code,
            'channel'=>'No_Shipping_Service','draft_only'=>true,
            'stock_evidence_mode'=>$targetedStockVerified?'scoped_fresh_oms_inventory':'standard_applied_snapshot',
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

    private function authorizedTargetSkuStock(Order $order, ?string $requestId): bool
    {
        if (! $requestId || ! (bool) config('xlwms.reissue_create_enabled', false)
            || (int) config('xlwms.reissue_order_id', 0) !== (int) $order->id
            || ! hash_equals((string)config('xlwms.reissue_request_id', ''), $requestId)) return false;
        $ship=$order->shipment;
        if (! $ship || $ship->warehouse?->code !== 'US-SM') return false;
        $request=XlwmsReshipment::query()->where('original_order_id',$order->id)
            ->where('request_id',$requestId)->first();
        if (! $request || ! in_array($request->status,['approved_for_reissue','prechecking'],true)
            || (int) $request->approved_by < 1 || filled($request->oms_order_no)) return false;
        $conn=WarehouseOpenApiConnection::query()
            ->where('warehouse_id',$ship->warehouse_id)->where('provider','xlwms')->first();
        $snapshot=XlwmsInventorySnapshot::query()->where('warehouse_id',$ship->warehouse_id)
            ->orderByDesc('id')->first();
        if (! $conn || ! $snapshot || $snapshot->status !== 'blocked'
            || ($snapshot->summary['v2'] ?? false) !== true
            || $snapshot->wh_code !== $conn->oms_warehouse_code
            || ! $snapshot->fetched_at || ! $snapshot->updated_at
            || $snapshot->fetched_at->gt($snapshot->updated_at)
            || $snapshot->updated_at->gt(now())
            || $snapshot->updated_at->diffInSeconds(now()) > 120) return false;
        $qtyBySku=[];
        foreach ($order->items as $item) {
            $sku=trim((string)($item->resolved_fulfillment_sku ?: $item->sku));
            $product=(int)$item->product_id;
            $qty=(int)$item->quantity;
            if ($sku==='' || $product<1 || $qty<1) return false;
            if (isset($qtyBySku[$sku]) && $qtyBySku[$sku]['product']!==$product) return false;
            $qtyBySku[$sku]=['product'=>$product,'qty'=>($qtyBySku[$sku]['qty']??0)+$qty];
        }
        if ($qtyBySku===[]) return false;
        foreach ($qtyBySku as $sku=>$expected) {
            $rows=$snapshot->rows()->where('provider_sku',$sku)->limit(2)->get();
            if ($rows->count()!==1) return false;
            $row=$rows->first();
            $free=$row->oms_available;
            $locked=$row->oms_locked;
            $total=$row->oms_total;
            if ($row->resolution_status!=='matched'
                || (int)$row->product_id!==$expected['product']
                || $free===null || $locked===null || $total===null
                || (int)$free<$expected['qty'] || (int)$locked<0
                || (int)$total!==(int)$free+(int)$locked) return false;
        }
        return true;
    }

}
