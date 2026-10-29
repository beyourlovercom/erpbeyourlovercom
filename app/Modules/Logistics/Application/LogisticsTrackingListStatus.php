<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Sync\Application\ManualSyncStatus;
use Illuminate\Support\Facades\DB;

final class LogisticsTrackingListStatus
{
    /**
     * Read-only one-query projection. Never treats a completed outbox for an
     * earlier Tracking number as proof that the current Shipment was synced.
     *
     * @param list<int> $shipmentIds
     * @return array<int, array{status:string,reason:?string}>
     */
    public function forShipments(array $shipmentIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $shipmentIds))));
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('sync_outbox')
            ->where('operation', 'woocommerce.tracking_outbound')
            ->where('aggregate_type', 'shipment')
            ->whereIn('aggregate_id', array_map('strval', $ids))
            ->orderByDesc('id')
            ->get(['id', 'aggregate_id', 'status', 'last_error_code']);

        $result = [];
        foreach ($rows as $row) {
            $id = (int) $row->aggregate_id;
            if (isset($result[$id])) {
                continue;
            }

            $code = (string) ($row->last_error_code ?? '');
            $status = (string) $row->status;
            $result[$id] = [
                'status' => $status === 'pending' && $code !== '' ? 'retry_wait' : $status,
                'reason' => match ($code) {
                    'remote_tracking_conflict' => '官网已有不同的物流信息，请人工核对，系统不会覆盖',
                    'woocommerce_tracking_write_failed' => '物流写回失败，检查连接后重试',
                    'woocommerce_tracking_verify_failed' => '官网 Tracking 回读验证失败',
                    'retry_runner_exception' => '自动重试异常，请查看同步记录',
                    default => $code !== '' ? '同步出现异常，请查看详细同步记录' : null,
                },
            ];
        }

        return $result;
    }

    /** @param array{status:string,reason:?string}|null $outbox */
    public function currentStatus(?array $outbox, ManualSyncStatus|string|null $manualSyncStatus): array
    {
        // Eloquent casts the canonical status to a backed enum; list projections
        // also accept serialized status strings from older callers.
        if ($manualSyncStatus instanceof ManualSyncStatus) {
            $manualSyncStatus = $manualSyncStatus->value;
        }

        // Canonical manual-sync state must agree before a completed badge is shown.
        if ($manualSyncStatus !== 'completed' && ($outbox['status'] ?? '') === 'completed') {
            return ['status' => 'pending', 'reason' => null];
        }

        return $outbox ?? [
            'status' => $manualSyncStatus === 'completed' ? 'completed' : 'pending',
            'reason' => null,
        ];
    }
}
