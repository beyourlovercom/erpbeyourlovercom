<?php
namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Logistics\Infrastructure\Models\Carrier;
use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
use App\Modules\Logistics\Infrastructure\Xlwms\WarehouseOpenApiConnection;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsOrderGateway;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsReshipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** M2: trusted provider detail -> read-only preflight -> separately gated ERP shipment.
 * No OMS CREATE/SUBMIT/CANCEL and no Woo or AfterShip network writes here.
 */
final class ReshipmentFulfillmentService
{
    public function __construct(
        private readonly XlwmsOrderGateway $gateway,
        private readonly ShipmentService $shipments
    ) {}

    public function preview(int $orderId, string $requestId): array
    {
        $r=XlwmsReshipment::query()->where('original_order_id',$orderId)
            ->where('request_id',$requestId)->firstOrFail();
        if (!filled($r->oms_order_no) || !in_array($r->status,['draft_created','shipped_applied'],true)) {
            throw new RuntimeException('M2_OMS_DRAFT_NOT_CONFIRMED');
        }
        $o=Order::query()->with(['shipment.warehouse','shipment.overseaReview'])->findOrFail($orderId);
        $s=$o->shipment;
        $c=WarehouseOpenApiConnection::query()->where('warehouse_id',$r->warehouse_id)
            ->where('provider','xlwms')->firstOrFail();
        $d=$this->gateway->readExactOrderDetail($c,$r->oms_order_no,$r->third_order_no);
        $expected=[];
        foreach (($r->sku_quantity_snapshot??[]) as $item) {
            $sku=trim((string)($item['sku']??''));
            $qty=(int)($item['quantity']??0);
            if ($sku==='' || $qty<1) throw new RuntimeException('M2_SOURCE_SKU_INVALID');
            $expected[$sku]=($expected[$sku]??0)+$qty;
        }
        ksort($expected);
        $block=[];
        if ($d['items']!==$expected) $block[]='M2_SKU_QUANTITY_MISMATCH';
        if ($s?->warehouse?->code!=='US-SM' || (int)$s->id!==(int)$r->original_shipment_id
            || (int)$s->warehouse_id!==(int)$r->warehouse_id) $block[]='M2_ERP_WAREHOUSE_CHANGED';
        if ($s?->overseaReview?->status!=='approved'
            || (int)$s->overseaReview?->reviewed_warehouse_id!==(int)$r->warehouse_id) {
            $block[]='M2_DEDE_REVIEW_REQUIRED';
        }
        if ($d['status_code']!=='3' || $d['status_name']!=='已出库') {
            $block[]='M2_OMS_NOT_OUTBOUND';
        }
        if ($d['status_code']==='3' && !preg_match(
            '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D',
            $d['outbound_time']
        )) $block[]='M2_OUTBOUND_TIME_MISSING';
        $tracking=$d['tracking_number'];
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/+\-]{0,119}$/D',$tracking)
            || preg_match('/^(MOCK|TEST|VOID|UAT)/i',$tracking)) {
            $block[]='M2_TRACKING_NOT_VERIFIED';
        }
        $carrier=$this->canonicalCarrier($d['carrier']);
        if ($carrier===null || !Carrier::query()->where('name',$carrier)->where('is_active',true)->exists()) {
            $block[]='M2_CARRIER_NOT_VERIFIED';
        }
        if ($r->status==='shipped_applied') {
            $block[]='M2_ALREADY_APPLIED';
        } elseif ($o->order_status?->value!=='pending_shipment'
            || $s?->shipment_status!=='pending' || filled($s?->tracking_number)) {
            $block[]='M2_SOURCE_ORDER_ALREADY_CHANGED';
        }
        $token=hash('sha256',json_encode([
            'request_id'=>$requestId,'order_id'=>$orderId,'oms'=>$d['oms_order_no'],
            'status'=>$d['status_code'],'outbound_time'=>$d['outbound_time'],
            'tracking'=>$tracking,'carrier'=>$carrier,'items'=>$d['items'],
        ],JSON_THROW_ON_ERROR));
        return [
            'order_id'=>$orderId,'request_id'=>$requestId,'oms_order_no'=>$d['oms_order_no'],
            'status_code'=>$d['status_code'],'status_name'=>$d['status_name'],
            'outbound_time_present'=>$d['outbound_time']!=='',
            'tracking_number'=>$tracking?:null,'carrier'=>$carrier,
            'items'=>$d['items'],'provider_cost_present'=>$d['cost_present'],
            'cost_status'=>$r->cost_status,
            'erp_order_status'=>$o->order_status?->value,
            'blockers'=>array_values(array_unique($block)),
            'ready_for_erp_review'=>$block===[],
            'can_apply'=>$block===[]
                && (bool)config('xlwms.m2_apply_enabled',false)
                && (int)config('xlwms.m2_order_id',0)===$orderId
                && hash_equals((string)config('xlwms.m2_request_id',''),$requestId),
            'review_token'=>$block===[]?$token:null,
            'website_sync'=>'Vita manual tracking sync; no auto Woo/AfterShip write',
        ];
    }

    public function apply(int $orderId, string $requestId, string $token, Authenticatable $actor): array
    {
        if (!(bool)config('xlwms.m2_apply_enabled',false)
            || (int)config('xlwms.m2_order_id',0)!==$orderId
            || !hash_equals((string)config('xlwms.m2_request_id',''),$requestId)
            || !preg_match('/^[0-9a-f]{64}$/D',$token)) {
            throw new RuntimeException('M2_APPLY_NOT_AUTHORIZED');
        }
        $fresh=$this->preview($orderId,$requestId);
        if (!$fresh['can_apply'] || !hash_equals((string)($fresh['review_token']??''),$token)) {
            throw new RuntimeException('M2_PREFLIGHT_CHANGED');
        }
        DB::transaction(function () use($orderId,$requestId,$fresh,$actor): void {
            $o=Order::query()->with(['shipment.warehouse','shipment.overseaReview'])
                ->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $r=XlwmsReshipment::query()->where('original_order_id',$orderId)
                ->where('request_id',$requestId)->lockForUpdate()->firstOrFail();
            if ($r->status!=='draft_created' || $r->oms_order_no!==$fresh['oms_order_no']
                || $o->order_status?->value!=='pending_shipment'
                || $o->shipment?->shipment_status!=='pending'
                || filled($o->shipment?->tracking_number)
                || $o->shipment?->warehouse?->code!=='US-SM'
                || (int)$o->shipment->id!==(int)$r->original_shipment_id
                || $o->shipment?->overseaReview?->status!=='approved') {
                throw new RuntimeException('M2_SOURCE_CHANGED_DURING_APPLY');
            }
            // Uses the existing order/shipment, FIFO, accounting and ManualSync logic.
            $this->shipments->markShipped($o,[
                'warehouse_id'=>$r->warehouse_id,
                'carrier'=>$fresh['carrier'],
                'service'=>strcasecmp((string)$o->shipment->carrier,$fresh['carrier'])===0
                    ? $o->shipment->service : null,
                'tracking_number'=>$fresh['tracking_number'],
                // OMS outboundTime has unverified timezone; use time of ERP confirmation.
                'shipped_at'=>now()->utc(),
                'logistics_note'=>$o->shipment->logistics_note,
            ],$actor);
            OverseaWarehouseReview::query()->where('order_id',$orderId)->update(['progress'=>'shipped']);
            $r->forceFill([
                'status'=>'shipped_applied',
                'oms_status_code'=>$fresh['status_code'],
                'oms_tracking_no'=>$fresh['tracking_number'],
                'oms_status_checked_at'=>now(),
                // Additional cost remains pending until verified invoice/FX evidence.
                'cost_status'=>'pending',
            ])->save();
            DB::table('xlwms_reshipment_events')->insert([
                'reshipment_id'=>$r->id,'previous_status'=>'draft_created',
                'status'=>'shipped_applied','event_code'=>'m2_erp_shipment_applied',
                'actor_id'=>$actor->getAuthIdentifier(),'oms_order_no'=>$r->oms_order_no,
                'created_at'=>now(),
            ]);
        });
        return ['status'=>'erp_shipped_website_pending','order_id'=>$orderId,
            'oms_order_no'=>$fresh['oms_order_no'],'tracking_number'=>$fresh['tracking_number'],
            'website_sync'=>'pending_manual_vita','cost_status'=>'pending'];
    }

    private function canonicalCarrier(string $source): ?string
    {
        return match(strtoupper(trim($source))) {
            'USPS'=>'USPS','UPS'=>'UPS','FEDEX'=>'FedEx','DHL'=>'DHL',default=>null
        };
    }
}
