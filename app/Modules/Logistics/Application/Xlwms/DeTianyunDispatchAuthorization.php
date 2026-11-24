<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Models\User;
use App\Modules\Logistics\Application\LogisticsExecutionAccess;
use Illuminate\Support\Facades\DB;

final class DeTianyunDispatchAuthorization
{
    public static function permits(int $id, ?int $actorId = null, ?string $hash = null): bool
    {
        if (in_array($id, (array) config('xlwms.de_order_batch_live_order_ids', []), true)) {
            return true;
        }
        if (! config('xlwms.de_operator_batch_enabled', false)) {
            return false;
        }
        $item = DB::table('xlwms_de_dispatch_batch_items as i')
            ->join('xlwms_de_dispatch_batches as b', 'b.id', '=', 'i.batch_id')
            ->where('i.order_id', $id)->where('i.status', 'processing')
            ->where('b.status', 'running')->where('b.dry_run', false)
            ->select('i.payload_hash', 'b.operator_user_id')->first();
        if (! $item || ($actorId !== null && (int) $item->operator_user_id !== $actorId)
            || ($hash !== null && ! hash_equals((string) $item->payload_hash, $hash))) {
            return false;
        }
        $actor = User::query()->find($item->operator_user_id);

        return $actor?->is_active && app(LogisticsExecutionAccess::class)->allows($actor);
    }

    /** The actual HTTP write also requires the durable request-in-flight claim. */
    public static function permitsCreate(int $id, int $warehouseId, array $payload): bool
    {
        if (in_array($id, (array) config('xlwms.de_order_batch_live_order_ids', []), true)) {
            return true;
        }
        if (! config('xlwms.de_operator_batch_enabled', false) || $warehouseId < 1) {
            return false;
        }
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $attempt = DB::table('xlwms_dispatch_attempts')->where('order_id', $id)
            ->where('warehouse_id', $warehouseId)->where('state', 'request_in_flight')
            ->where('third_order_no', 'BYL-ERP-'.$id)->where('request_fingerprint', $hash)->first();

        return $attempt !== null && self::permits($id, (int) $attempt->requested_by, $hash);
    }
}
