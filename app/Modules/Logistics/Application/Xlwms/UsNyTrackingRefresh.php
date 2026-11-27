<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\DB;

/** Tracking-only polling; never creates OMS orders or changes shipment status. */
final class UsNyTrackingRefresh
{
    public function __construct(private readonly UsNyTrackingStage $stage) {}

    public function run(): array
    {
        $warehouses = DB::table('warehouses')->where('code', 'US-NY')->select('id');
        $pending = Order::query()->eligibleForOperations()
            ->where('order_status', 'pending_shipment')
            ->whereHas('shipment', fn ($s) => $s->whereIn('warehouse_id', $warehouses)
                ->where('shipment_status', 'pending')->whereNull('shipped_at')
                ->where(fn ($q) => $q->whereNull('tracking_number')->orWhere('tracking_number', ''))
                ->whereHas('overseaReview', fn ($r) => $r->where('status', 'approved')
                    ->whereColumn('reviewed_warehouse_id', 'shipments.warehouse_id')))
            ->select('orders.id');
        $ids = XlwmsDispatchAttempt::query()->whereIn('warehouse_id', $warehouses)
            ->where('state', 'oms_create_confirmed')->whereNotNull('oms_order_no')
            ->where('oms_order_no', '!=', '')
            ->where(fn ($q) => $q->whereNull('oms_status_code')->orWhereNotIn('oms_status_code', ['3', '4']))
            ->whereIn('order_id', $pending)
            ->orderBy('updated_at')->orderBy('id')->limit(20)->pluck('order_id');
        $result = ['checked' => $ids->count(), 'refreshed' => 0, 'failed' => 0, 'tracking_staged' => 0];
        foreach ($ids as $id) {
            try {
                $r = $this->stage->refreshAndStage((int) $id);
                $result['refreshed'] += (int) ($r['fresh'] ?? false);
                $result['failed'] += (int) ! ($r['fresh'] ?? false);
                $result['tracking_staged'] += (int) ($r['staged'] ?? false);
            } catch (\Throwable) {
                // No payload/PII/credentials in logs. Continue with other orders.
                $result['failed']++;
            }
        }

        return $result;
    }
}
