<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\DB;

/** Explicit recovery only: a prior pre-CREATE failure is not a provider write. */
final class DeTianyunPreflightRecovery
{
    public const PREFIX = 'DE_RECOVER_PREFLIGHT:';

    public static function eligible(XlwmsDispatchAttempt $attempt, Order $order, int $attemptId): bool
    {
        return (int) $attempt->id === $attemptId
            && (int) $attempt->order_id === (int) $order->id
            && (int) $attempt->warehouse_id === (int) $order->shipment?->warehouse_id
            && $order->shipment?->warehouse?->code === 'DE'
            && $attempt->third_order_no === 'BYL-ERP-'.$order->id
            && $attempt->state === 'blocked_preflight_error'
            && $attempt->failure_code === 'DE_OMS_PREFLIGHT_OR_DUPLICATE_LOOKUP_FAILED'
            && blank($attempt->oms_order_no)
            && blank($attempt->oms_status_code)
            && blank($attempt->oms_tracking_no)
            && blank($attempt->oms_status_checked_at)
            && blank($attempt->oms_status_failure_code)
            && blank($attempt->duplicate_match_field)
            && DB::table('xlwms_de_dispatch_batch_items as i')
                ->join('xlwms_de_dispatch_batches as b', 'b.id', '=', 'i.batch_id')
                ->where('i.order_id', $order->id)->where('i.payload_hash', $attempt->request_fingerprint)
                ->where('i.status', 'skipped')->where('i.result_code', $attempt->failure_code)
                ->where('b.dry_run', false)->where('b.operator_user_id', $attempt->requested_by)
                ->whereIn('b.status', ['completed', 'completed_partial'])->exists()
            && ! DB::table('xlwms_de_dispatch_batch_items')->where('order_id', $order->id)
                ->whereIn('status', ['created', 'uncertain'])->exists();
    }

    public static function claimed(int $orderId, int $actorId, string $hash, int $attemptId): bool
    {
        return DB::table('xlwms_de_dispatch_batch_items as i')
            ->join('xlwms_de_dispatch_batches as b', 'b.id', '=', 'i.batch_id')
            ->where('i.order_id', $orderId)->where('i.status', 'processing')
            ->where('i.result_code', self::PREFIX.$attemptId)
            ->where('i.payload_hash', $hash)->where('b.operator_user_id', $actorId)
            ->where('b.status', 'running')->where('b.dry_run', false)->exists();
    }
}
