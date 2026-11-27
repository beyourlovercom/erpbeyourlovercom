<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\DB;

final class DeTianyunStatusRefresh
{
    public function __construct(private readonly SingleOrderDispatch $single, private readonly AuditRecorder $audit) {}

    public function run(): array
    {
        $ids = XlwmsDispatchAttempt::query()->whereIn('warehouse_id', DB::table('warehouses')->where('code', 'DE')->select('id'))
            ->where('state', 'oms_create_confirmed')->whereNotNull('oms_order_no')
            ->where(fn ($q) => $q->whereNull('oms_status_code')->orWhereNotIn('oms_status_code', ['3', '4']))
            ->whereIn('order_id', Order::query()->eligibleForOperations()->where('order_status', 'pending_shipment')
                ->whereHas('shipment', fn ($q) => $q->where('shipment_status', 'pending')->whereNull('shipped_at')
                    ->where(fn ($t) => $t->whereNull('tracking_number')->orWhere('tracking_number', ''))
                    ->whereHas('warehouse', fn ($w) => $w->where('code', 'DE'))
                    ->whereHas('overseaReview', fn ($r) => $r->where('status', 'approved')
                        ->whereColumn('reviewed_warehouse_id', 'shipments.warehouse_id')))
                ->select('orders.id'))
            ->orderBy('updated_at')->orderBy('id')->limit(20)->pluck('order_id');
        $ok = 0;
        $staged = 0;
        foreach ($ids as $id) {
            try {
                if ($this->single->checkStatus((int) $id)['fresh'] ?? false) {
                    if ($this->stageTracking((int) $id)) {
                        $staged++;
                    }
                    $ok++;
                }
            } catch (\Throwable) {
                // Keep previously verified status; no provider POST or retry.
            }
        }

        return ['checked' => $ids->count(), 'refreshed' => $ok, 'failed' => $ids->count() - $ok, 'tracking_staged' => $staged];
    }

    // A DHL label may exist while the warehouse is still processing. Stage
    // only the tracking field, preserve pending status and Vita's Woo gate.
    // Never overwrite a different number already recorded by the operator.
    private function stageTracking(int $orderId): bool
    {
        return DB::transaction(function () use ($orderId): bool {
            $order = Order::query()->eligibleForOperations()->whereKey($orderId)->lockForUpdate()->first();
            if (! $order || $order->order_status?->value !== 'pending_shipment') {
                return false;
            }
            $shipment = $order->shipment()->lockForUpdate()->first();
            $attempt = XlwmsDispatchAttempt::query()->where('order_id', $orderId)
                ->where('state', 'oms_create_confirmed')->lockForUpdate()->first();
            $tracking = trim((string) $attempt?->oms_tracking_no);
            if (! $shipment || ! $attempt || $shipment->warehouse?->code !== 'DE'
                || (int) $shipment->warehouse_id !== (int) $attempt->warehouse_id
                || $attempt->oms_status_failure_code !== null
                || ! in_array((string) $attempt->oms_status_code, ['2', '3'], true)
                || $attempt->third_order_no !== 'BYL-ERP-'.$orderId
                || $shipment->shipment_status !== 'pending' || $shipment->shipped_at !== null
                || $shipment->overseaReview?->status !== 'approved'
                || (int) $shipment->overseaReview?->reviewed_warehouse_id !== (int) $shipment->warehouse_id
                || strcasecmp(trim((string) $shipment->carrier), 'DHL') !== 0
                || ! preg_match('/^[A-Za-z0-9-]{6,80}$/D', $tracking)
                || preg_match('/^(TEST|MOCK|VOID|UAT)/i', $tracking)) {
                return false;
            }
            if (filled($shipment->tracking_number)) {
                return false;
            }
            DB::table('shipments')->where('id', $shipment->id)->update([
                'tracking_number' => $tracking, 'updated_at' => now(),
            ]);
            $this->audit->record(AuditActor::system(AuditSource::InternalSystem),
                'shipment.de_oms_tracking_staged', Shipment::class, (int) $shipment->id,
                ['tracking_number' => null, 'shipment_status' => 'pending'],
                ['tracking_number' => $tracking, 'shipment_status' => 'pending', 'oms_order_no' => $attempt->oms_order_no],
                'de_oms_signed_tracking_readback');

            return true;
        }, 3);
    }
}
