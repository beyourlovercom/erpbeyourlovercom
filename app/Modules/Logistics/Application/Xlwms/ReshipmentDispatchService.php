<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Logistics\Application\Reshipment\ReshipmentProviderRegistry;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsReshipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Independent OMS reissue. Only single UUID + source-order allowlist can CREATE. */
final class ReshipmentDispatchService
{
    public function __construct(
        private readonly ReshipmentPreview $preview,
        private readonly SingleOrderPreview $regular,
        private readonly ReshipmentProviderRegistry $providers,
    ) {}

    public function createDraft(int $orderId, string $requestId, int $actorId, string $confirmation): array
    {
        if ($actorId < 1 || !hash_equals('CREATE_REISSUE_'.$requestId,$confirmation)
            || !(bool) config('xlwms.reissue_create_enabled',false)
            || (int) config('xlwms.reissue_order_id',0)!==$orderId
            || !hash_equals((string) config('xlwms.reissue_request_id',''),$requestId)) {
            throw new RuntimeException('REISSUE_CREATE_NOT_AUTHORIZED');
        }
        $record = DB::transaction(function () use ($orderId,$requestId,$actorId): XlwmsReshipment {
            Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $r = XlwmsReshipment::query()->where('original_order_id',$orderId)
                ->where('request_id',$requestId)->lockForUpdate()->first();
            if (!$r || $r->status!=='approved_for_reissue' || !$r->approved_by
                || filled($r->oms_order_no) || $r->requested_by<1) {
                throw new RuntimeException('REISSUE_CREATE_STATE_NOT_READY');
            }
            $r->forceFill(['status'=>'prechecking','last_error_code'=>null])->save();
            $this->event($r,'approved_for_reissue','prechecking','single_order_claimed',$actorId);
            return $r;
        });
        $reachedCreate=false;
        try {
            $context=$this->verifiedContext($record);
            $original=$this->providers->get('xlwms')->readStatus([
                'connection'=>$context['connection'],
                'oms_order_no'=>$record->original_oms_order_no,
            ]);
            if (!$original || !in_array($original['status_code'],['3','4'],true)) {
                throw new RuntimeException('REISSUE_ORIGINAL_NOT_SAFE');
            }
            $expectedOriginalThird='BYL-ERP-'.$orderId;
            if (filled($original['third_order_no'])
                && $original['third_order_no']!==$expectedOriginalThird) {
                throw new RuntimeException('REISSUE_ORIGINAL_IDENTITY_MISMATCH');
            }
            // A second reissue is permitted only after every earlier OMS reissue
            // was conclusively cancelled. Never treat a cached cancellation as
            // permission to create another live warehouse order.
            $earlier=XlwmsReshipment::query()
                ->where('original_order_id',$orderId)->where('id','!=',$record->id)->get();
            foreach ($earlier as $prior) {
                if (! filled($prior->oms_order_no)) {
                    if (! in_array($prior->status,['rejected','canceled'],true)) {
                        throw new RuntimeException('REISSUE_PREVIOUS_REQUEST_ACTIVE');
                    }
                    continue;
                }
                if ($prior->status!=='canceled') {
                    throw new RuntimeException('REISSUE_PREVIOUS_REQUEST_ACTIVE');
                }
                $checked=$this->providers->get('xlwms')->readStatus([
                    'connection'=>$context['connection'],'oms_order_no'=>$prior->oms_order_no,
                ]);
                if (! $checked || $checked['order_no']!==$prior->oms_order_no
                    || $checked['third_order_no']!==$prior->third_order_no
                    || $checked['platform_order_no']!==$prior->third_order_no
                    || $checked['status_code']!=='4'
                    || filled($checked['tracking_no'])) {
                    throw new RuntimeException('REISSUE_PREVIOUS_OMS_NOT_CANCELED');
                }
            }
            $existing=$this->providers->get('xlwms')->findExistingExact($context);
            if ($existing!==null) {
                $omsNo=trim((string)($existing['outboundOrderNo']??$existing['orderNo']??''));
                if ($omsNo==='') throw new RuntimeException('REISSUE_DUPLICATE_WITHOUT_OMS_ID');
                $this->finalize($record,'draft_existing',$omsNo,$actorId);
                return ['result'=>'existing','request_id'=>$requestId,'oms_order_no'=>$omsNo,'isSubmit'=>0];
            }
            $this->providers->get('xlwms')->assertCatalogReady($context);
            $record->forceFill(['status'=>'create_in_flight'])->save();
            $this->event($record,'prechecking','create_in_flight','provider_create_started',$actorId);
            $reachedCreate=true;
            $created=$this->providers->get('xlwms')->createDraft($context);
            if (($created['confirmed']??false)!==true || !filled($created['order_no']??null)) {
                throw new RuntimeException('REISSUE_PROVIDER_RESULT_UNCERTAIN');
            }
            $omsNo=(string)$created['order_no'];
            if (($created['draft_verified']??false)!==true) {
                $this->finalize($record,'draft_unverified',$omsNo,$actorId);
                $error=$created['draft_verify_code']??'OMS_DRAFT_READBACK_UNAVAILABLE';
                $record->forceFill(['last_error_code'=>$error,
                    'oms_status_code'=>$created['oms_status_code']??null])->save();
                $this->event($record,'draft_unverified','draft_unverified','provider_draft_state_not_verified',$actorId);
                return ['result'=>'draft_unverified','request_id'=>$requestId,
                    'oms_order_no'=>$omsNo,'code'=>$error,'can_retry_create'=>false,'isSubmit'=>0];
            }
            $this->finalize($record,'draft_created',$omsNo,$actorId);
            return ['result'=>'created','request_id'=>$requestId,'oms_order_no'=>$omsNo,'isSubmit'=>0];
        } catch (\Throwable $e) {
            // Once CREATE may have reached OMS, never retry it blindly.
            $state=$reachedCreate?'create_uncertain':'approved_for_reissue';
            $error=preg_match('/^[A-Z0-9_]+$/D',$e->getMessage())
                ? $e->getMessage():'REISSUE_PROVIDER_UNEXPECTED';
            $record->forceFill(['status'=>$state,'last_error_code'=>$error])->save();
            $this->event($record,$reachedCreate?'create_in_flight':'prechecking',$state,
                $reachedCreate?'create_uncertain_no_retry':'precreate_blocked',$actorId);
            return ['result'=>$reachedCreate?'uncertain':'blocked','code'=>$error,
                'request_id'=>$requestId,'can_retry_create'=>!$reachedCreate];
        }
    }

    private function verifiedContext(XlwmsReshipment $record): array
    {
        $order=Order::query()->with(['address','items','shipment.warehouse'])->findOrFail($record->original_order_id);
        $shipment=$order->shipment;
        if (! $shipment || $shipment->warehouse?->code!=='US-SM'
            || (int)$shipment->id!==(int)$record->original_shipment_id
            || (int)$shipment->warehouse_id!==(int)$record->warehouse_id
            || !filled($record->original_oms_order_no)
            || !str_starts_with($record->third_order_no,'BYL-RESHIP-')) {
            throw new RuntimeException('REISSUE_SOURCE_INVALID');
        }
        $review=$this->preview->forOrder($order->id,$record->request_id,true);
        if ($review['blockers']!==[]) throw new RuntimeException('REISSUE_PREFLIGHT_BLOCKED');
        $fingerprint=hash('sha256',json_encode([
            'order_id'=>(int)$order->id,'shipment_id'=>(int)$shipment->id,
            'items'=>$review['items'],'recipient'=>$review['recipient'],
            'address'=>$review['address'],'reason'=>$record->reason,
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        if (!hash_equals($record->payload_fingerprint,$fingerprint)
            || $review['items']!==$record->sku_quantity_snapshot) {
            throw new RuntimeException('REISSUE_SOURCE_FINGERPRINT_CHANGED');
        }
        $base=$this->regular->forOrder($order,true);
        $p=$base['payload'];
        $p['thirdOrderNo']=$record->third_order_no;
        $p['platformOrderNo']=$record->third_order_no;
        $p['referOrderNo']=$record->third_order_no;
        $p['isSubmit']=0;
        $connection=WarehouseOpenApiConnection::query()
            ->where('warehouse_id',$shipment->warehouse_id)
            ->where('provider','xlwms')->firstOrFail();
        if ($p['whCode']!==$connection->oms_warehouse_code) {
            throw new RuntimeException('REISSUE_WAREHOUSE_BINDING_MISMATCH');
        }
        return ['payload'=>$p,'connection'=>$connection];
    }

    private function finalize(XlwmsReshipment $record,string $state,string $omsNo,int $actorId): void
    {
        DB::transaction(function () use ($record,$state,$omsNo,$actorId): void {
            $record->forceFill(['status'=>$state,'oms_order_no'=>$omsNo,
                'last_error_code'=>null,'oms_checked_at'=>now()])->save();
            $this->event($record,$state==='draft_created'?'create_in_flight':'prechecking',
                $state,'provider_identity_confirmed',$actorId);
        });
    }

    public function readStatus(int $orderId,string $requestId,int $actorId): array
    {
        if($actorId<1) throw new RuntimeException('REISSUE_ACTOR_REQUIRED');
        $record=XlwmsReshipment::query()->where('original_order_id',$orderId)
            ->where('request_id',$requestId)->firstOrFail();
        if(!filled($record->oms_order_no)) throw new RuntimeException('REISSUE_OMS_NOT_CREATED');
        $conn=WarehouseOpenApiConnection::query()->where('warehouse_id',$record->warehouse_id)
            ->where('provider','xlwms')->firstOrFail();
        $remote=$this->providers->get('xlwms')->readStatus(['connection'=>$conn,'oms_order_no'=>$record->oms_order_no]);
        if(!$remote || $remote['order_no']!==$record->oms_order_no
            || $remote['third_order_no']!==$record->third_order_no
            || $remote['platform_order_no']!==$record->third_order_no) {
            throw new RuntimeException('REISSUE_STATUS_IDENTITY_MISMATCH');
        }
        // A native cancelled reissue with no tracking is the *only* route
        // to closing an earlier attempt and safely permitting a new request.
        // Preserve the provider order ID and complete audit trail.
        DB::transaction(function () use ($record,$remote,$actorId): void {
            $locked=XlwmsReshipment::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $before=$locked->status;
            $canceled=$remote['status_code']==='4' && ! filled($remote['tracking_no'])
                && in_array($before,['draft_created','draft_existing','draft_unverified'],true);
            $after=$canceled?'canceled':$before;
            $locked->forceFill(['status'=>$after,'oms_status_code'=>$remote['status_code'],
                'oms_tracking_no'=>$remote['tracking_no'],
                'oms_status_checked_at'=>now(),'last_error_code'=>null])->save();
            $this->event($locked,$before,$after,
                $canceled?'provider_canceled_confirmed':'provider_status_readback',$actorId);
        });
        return ['request_id'=>$requestId,'oms_order_no'=>$record->oms_order_no,
            'oms_status_code'=>$remote['status_code'],'oms_tracking_no'=>$remote['tracking_no'],
            'read_only_shipping'=>true,'original_erp_unchanged'=>true];
    }

    private function event(XlwmsReshipment $r,?string $before,string $after,string $code,int $actor): void
    {
        DB::table('xlwms_reshipment_events')->insert([
            'reshipment_id'=>$r->id,'previous_status'=>$before,
            'status'=>$after,'event_code'=>$code,'actor_id'=>$actor,
            'oms_order_no'=>$r->oms_order_no,'created_at'=>now(),
        ]);
    }
}
