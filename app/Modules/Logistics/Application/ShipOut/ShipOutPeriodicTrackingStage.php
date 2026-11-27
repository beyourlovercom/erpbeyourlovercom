<?php

namespace App\Modules\Logistics\Application\ShipOut;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutProductionReadOnlyGateway;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\DB;

final class ShipOutPeriodicTrackingStage
{
    public function __construct(private readonly ShipOutProductionReadOnlyGateway $reader, private readonly AuditRecorder $audit) {}

    /** Only verified single USPS label; ambiguous or missing proof is never applied. */
    public function stage(int $id): bool
    {
        $a = DB::table('shipout_dispatch_attempts')->where('order_id', $id)
            ->where('provider_warehouse_id', ShipOutProductionDispatchState::MOREPLUS_PROVIDER_WAREHOUSE_ID)
            ->where('status', 'acknowledged')->first();
        $last = DB::table('shipout_oms_readbacks')->where('order_id', $id)->latest('id')->first();
        if (! $a || ! $last || ! in_array($last->state, ['warehouse_awaiting', 'visible', 'confirmed', 'provider_shipped'], true)
            || ! filled($a->provider_order_id) || $last->provider_order_id !== $a->provider_order_id) {
            return false;
        }
        $proof = $this->reader->discoverTracking((string) $a->provider_warehouse_id, (string) $a->provider_order_id, (string) $a->order_no);

        return DB::transaction(function () use ($id, $a, $last, $proof): bool {
            $o = Order::query()->eligibleForOperations()->whereKey($id)->lockForUpdate()->first();
            $s = $o?->shipment()->lockForUpdate()->first();
            $current = DB::table('shipout_oms_readbacks')->where('order_id', $id)->latest('id')->first();
            if (! $o || ! $s || $current?->id !== $last->id
                || $o->order_status?->value !== 'pending_shipment' || $s->shipment_status !== 'pending'
                || strtoupper((string) $s->warehouse?->code) !== 'US-LA' || $s->shipped_at !== null
                || $s->overseaReview?->status !== 'approved'
                || (int) $s->overseaReview?->reviewed_warehouse_id !== (int) $s->warehouse_id
                || strcasecmp(trim((string) $s->carrier), 'USPS') !== 0
                || filled($s->tracking_number)) {
                return false;
            }
            DB::table('shipments')->where('id', $s->id)->update(['tracking_number' => $proof['tracking_number'], 'updated_at' => now()]);
            $this->audit->record(AuditActor::system(AuditSource::InternalSystem), 'shipment.moreplus_oms_tracking_staged',
                Shipment::class, (int) $s->id, ['tracking_number' => null], [
                    'tracking_number' => $proof['tracking_number'], 'shipment_status' => 'pending', 'oms_order_no' => $a->provider_order_id,
                ], 'moreplus_signed_exact_outbound_readback');

            return true;
        });
    }
}
