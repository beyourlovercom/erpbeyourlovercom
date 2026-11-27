<?php
namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\DB;

final class UsNyTrackingStage
{
    public function __construct(private readonly SingleOrderDispatch $status, private readonly AuditRecorder $audit) {}

    public function refreshAndStage(int $id): array
    {
        $attempt = XlwmsDispatchAttempt::query()->where('order_id',$id)->where('state','oms_create_confirmed')->firstOrFail();
        $order = Order::query()->eligibleForOperations()->with('shipment.warehouse')->findOrFail($id);
        abort_unless($order->shipment?->warehouse?->code === 'US-NY'
            && (int)$order->shipment->warehouse_id === (int)$attempt->warehouse_id, 422);
        $result = $this->status->checkStatus($id);
        if (!($result['fresh'] ?? false)) return ['fresh'=>false,'staged'=>false,'tracking_present'=>false];
        return DB::transaction(function () use ($id) {
            $o = Order::query()->eligibleForOperations()->whereKey($id)->lockForUpdate()->firstOrFail();
            $s = $o->shipment()->lockForUpdate()->first();
            $a = XlwmsDispatchAttempt::query()->where('order_id',$id)->where('state','oms_create_confirmed')->lockForUpdate()->first();
            $tracking = trim((string)$a?->oms_tracking_no);
            $ready = $s && $a && $s->warehouse?->code === 'US-NY'
                && (int)$s->warehouse_id === (int)$a->warehouse_id
                && $a->third_order_no === 'BYL-ERP-'.$id && $a->oms_status_failure_code === null
                && $a->oms_status_checked_at && now()->diffInSeconds($a->oms_status_checked_at, true) <= 120
                && in_array((string)$a->oms_status_code,['2','3'],true)
                && $o->order_status?->value === 'pending_shipment' && $s->shipment_status === 'pending'
                && $s->shipped_at === null && $s->overseaReview?->status === 'approved'
                && (int)$s->overseaReview?->reviewed_warehouse_id === (int)$s->warehouse_id
                && strcasecmp((string)$s->carrier,'USPS') === 0
                && preg_match('/^[0-9]{20,34}$/D',$tracking);
            if (!$ready || filled($s?->tracking_number)) return ['fresh'=>true,'staged'=>false,
                'tracking_present'=>$tracking !== '', 'already_matches'=>$ready && $s->tracking_number === $tracking];
            DB::table('shipments')->where('id',$s->id)->update(['tracking_number'=>$tracking,'updated_at'=>now()]);
            $this->audit->record(AuditActor::system(AuditSource::InternalSystem), 'shipment.us_ny_oms_tracking_staged',
                Shipment::class,$s->id,['tracking_number'=>null],['tracking_number'=>$tracking,'shipment_status'=>'pending'],
                'us_ny_signed_exact_order_readback');
            return ['fresh'=>true,'staged'=>true,'tracking_present'=>true,'tracking_number'=>$tracking];
        });
    }
}
