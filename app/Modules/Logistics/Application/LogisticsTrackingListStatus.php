<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Sync\Application\Contracts\WooCommerceTrackingBatchInspector;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;
use App\Modules\Sync\Application\ManualSyncStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class LogisticsTrackingListStatus
{
    /**
     * Read-only bounded-query projection. Never treats a completed outbox for an
     * earlier Tracking number as proof that the current Shipment was synced.
     *
     * @param  list<int>  $shipmentIds
     * @return array<int, array<string,mixed>>
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
            ->get(['id', 'aggregate_id', 'status', 'last_error_code', 'payload', 'completed_at']);

        $shipmentFacts = DB::table('shipments')->whereIn('id', $ids)->get(['id', 'tracking_number', 'carrier'])->keyBy('id');
        $currentTracking = $shipmentFacts->mapWithKeys(fn ($shipment): array => [(int) $shipment->id => $shipment->tracking_number]);
        $rootOrders = DB::table('shipments as s')
            ->join('orders as o', 'o.id', '=', 's.order_id')
            ->whereIn('s.id', $ids)
            ->get(['s.id as shipment_id', DB::raw('COALESCE(o.fulfillment_parent_id, o.id) as root_order_id')])
            ->keyBy('shipment_id');
        $statusOutboxes = DB::table('sync_outbox')
            ->where('operation', 'woocommerce.order_status_outbound')
            ->where('aggregate_type', 'order')
            ->whereIn('aggregate_id', $rootOrders->pluck('root_order_id')->map(fn ($id): string => (string) $id)->unique()->values()->all())
            ->orderByDesc('id')
            ->get(['aggregate_id', 'status', 'payload', 'completed_at'])
            ->unique('aggregate_id')
            ->keyBy('aggregate_id');
        $result = [];
        foreach ($rows as $row) {
            $id = (int) $row->aggregate_id;
            if (isset($result[$id])) {
                continue;
            }

            $code = (string) ($row->last_error_code ?? '');
            $status = (string) $row->status;
            $payload = is_array($row->payload) ? $row->payload : (json_decode((string) $row->payload, true) ?: []);
            $rootId = (string) ($rootOrders[$id]->root_order_id ?? '');
            $statusOutbox = $statusOutboxes->get($rootId);
            $statusPayload = is_array($statusOutbox?->payload) ? $statusOutbox->payload : (json_decode((string) ($statusOutbox?->payload ?? ''), true) ?: []);
            $statusVerified = $statusOutbox?->status === 'completed'
                && $statusOutbox?->completed_at !== null
                && ($statusPayload['woo_status'] ?? null) === 'completed';
            $trackingVerified = $status === 'completed' && $row->completed_at !== null
                && trim((string) ($payload['tracking_number'] ?? '')) !== ''
                && hash_equals(trim((string) ($currentTracking[$id] ?? '')), trim((string) ($payload['tracking_number'] ?? '')));
            $result[$id] = [
                'status' => $status === 'pending' && $code !== '' ? 'retry_wait' : $status,
                'reason' => match ($code) {
                    'remote_tracking_conflict' => '官网已有不同的物流信息，请人工核对，系统不会覆盖',
                    'woocommerce_tracking_write_failed' => '物流写回失败，检查连接后重试',
                    'woocommerce_tracking_verify_failed' => '官网 Tracking 回读验证失败',
                    'retry_runner_exception' => '自动重试异常，请查看同步记录',
                    default => $code !== '' ? '同步出现异常，请查看详细同步记录' : null,
                },
                'tracking_verified' => $trackingVerified,
                'verified_outbox' => $trackingVerified && $statusVerified,
                'status_outbox_status' => $statusOutbox?->status,
                'status_outbox_woo_status' => $statusPayload['woo_status'] ?? null,
                'outbox_tracking_number' => trim((string) ($payload['tracking_number'] ?? '')),
                'verified_at' => $statusVerified ? $statusOutbox->completed_at : $row->completed_at,
                'status_outbox_verified' => $statusVerified,
            ];
        }

        // This explicit per-fulfillment marker proves a verified operator
        // override even when an old automatic Outbox is pending/failed. The
        // corresponding ManualSync must still be completed; Tracking changes
        // reopen ManualSync so a stale marker alone cannot claim success.
        $verified = DB::table('shipments as s')
            ->join('order_operational_markers as m', 'm.order_id', '=', 's.order_id')
            ->leftJoin('manual_syncs as ms', 'ms.order_id', '=', 's.order_id')
            ->whereIn('s.id', $ids)
            ->where('m.marker', 'woo_manual_tracking_verified')
            ->get(['s.id', 's.tracking_number', 'ms.sync_note', 'ms.synced_at']);
        foreach ($verified as $record) {
            $id = (int) $record->id;
            $result[$id] ??= ['status' => 'pending', 'reason' => null];
            $fingerprint = 'verified_tracking_sha256:'.hash('sha256', trim((string) $record->tracking_number)).';';
            if (str_starts_with((string) $record->sync_note, $fingerprint)) {
                $result[$id]['verified_manual'] = true;
                $result[$id]['verified_tracking_fingerprint'] = true;
                $result[$id]['verified_at'] = $record->synced_at;
            } else {
                // A Tracking draft may change while Shipment remains pending.
                // Never show previous manual success for a different number.
                $result[$id]['status'] = 'pending';
                $result[$id]['reason'] = 'Tracking 已变化，请重新同步官网';
                $result[$id]['stale_manual_override'] = true;
            }
        }

        $this->applyRemoteTrackingSnapshots($rootOrders, $shipmentFacts, $result);

        return $result;
    }

    /**
     * Reconcile the current shipment Tracking against every package on the real Woo root.
     * One bounded Woo collection request covers up to 100 root orders; short cache TTL
     * prevents every table refresh from repeating the same remote read.
     *
     * @param  Collection<int,object>  $rootOrders
     * @param  Collection<int,object>  $shipmentFacts
     * @param  array<int,array<string,mixed>>  $result
     */
    private function applyRemoteTrackingSnapshots($rootOrders, $shipmentFacts, array &$result): void
    {
        $gateway = app(WooCommerceTrackingGateway::class);
        if (! $gateway instanceof WooCommerceTrackingBatchInspector) {
            return;
        }

        $descriptor = $gateway->descriptor();
        if (! ($descriptor['configured'] ?? false)) {
            return;
        }
        $connection = (string) ($descriptor['connection_key'] ?? '');
        $rootIds = $rootOrders->pluck('root_order_id')->filter()->unique()->values()->all();
        if ($rootIds === [] || $connection === '') {
            return;
        }

        $references = DB::table('order_external_references')
            ->whereIn('order_id', $rootIds)
            ->where('provider', 'woocommerce')
            ->where('connection_key', $connection)
            ->get(['order_id', 'reference'])
            ->keyBy('order_id');

        $providerByShipment = [];
        foreach ($rootOrders as $shipmentId => $root) {
            $shipment = $shipmentFacts->get($shipmentId);
            $reference = $references->get($root->root_order_id);
            if (! $shipment || trim((string) $shipment->tracking_number) === ''
                || ! $reference || ! ctype_digit((string) $reference->reference)) {
                continue;
            }
            $providerByShipment[(int) $shipmentId] = (int) $reference->reference;
        }
        if ($providerByShipment === []) {
            return;
        }

        $snapshots = [];
        $missing = [];
        foreach (array_unique(array_values($providerByShipment)) as $providerOrderId) {
            $key = $this->remoteSnapshotCacheKey($connection, $providerOrderId);
            $cached = Cache::get($key);
            if (is_array($cached)) {
                $snapshots[$providerOrderId] = $cached;
            } else {
                $missing[$providerOrderId] = $key;
            }
        }

        if ($missing !== []) {
            try {
                $fresh = $gateway->inspectTrackingBatch(array_keys($missing));
                foreach ($missing as $providerOrderId => $key) {
                    $snapshot = $fresh[$providerOrderId] ?? [
                        'order_status' => 'not_found', 'tracking_items' => [],
                        'has_tracking' => false, 'checked_at' => now()->toIso8601String(),
                    ];
                    $snapshots[$providerOrderId] = $snapshot;
                    Cache::put($key, $snapshot, now()->addSeconds(20));
                }
            } catch (\Throwable) {
                // A remote read outage must not take down a logistics list or
                // be mistaken for a successful verification. Retry shortly.
                foreach ($missing as $providerOrderId => $key) {
                    $snapshots[$providerOrderId] = ['read_error' => true];
                    Cache::put($key, ['read_error' => true], now()->addSeconds(8));
                }
            }
        }

        foreach ($providerByShipment as $shipmentId => $providerOrderId) {
            $snapshot = $snapshots[$providerOrderId] ?? null;
            $shipment = $shipmentFacts->get($shipmentId);
            if (is_array($snapshot) && ($snapshot['read_error'] ?? false) && $shipment) {
                // The ordinary list remains available, but a full-library
                // Sync Grid snapshot must detect this unverified Woo read.
                $result[$shipmentId] ??= ['status' => 'pending', 'reason' => null];
                $result[$shipmentId]['remote_read_error'] = true;

                continue;
            }
            if (! is_array($snapshot) || ! $shipment) {
                continue;
            }

            $trackingNumber = trim((string) $shipment->tracking_number);
            $matches = array_values(array_filter(
                (array) ($snapshot['tracking_items'] ?? []),
                fn ($item): bool => is_array($item)
                    && trim((string) ($item['tracking_number'] ?? '')) === $trackingNumber,
            ));
            if ($matches === []) {
                continue;
            }

            $expectedProvider = $this->providerSlugForCarrier((string) $shipment->carrier);
            $matchingProvider = array_filter($matches, fn (array $item): bool => $expectedProvider !== ''
                && trim((string) ($item['provider'] ?? '')) !== ''
                && trim((string) ($item['provider'] ?? '')) === $expectedProvider
            );
            if ($matchingProvider !== []) {
                $result[$shipmentId] = [
                    'status' => 'completed',
                    'reason' => null,
                    'verified_remote_tracking' => true,
                    'remote_order_status' => (string) ($snapshot['order_status'] ?? ''),
                    'verified_at' => $snapshot['checked_at'] ?? now()->toIso8601String(),
                ];

                continue;
            }

            $result[$shipmentId] = [
                'status' => 'conflict',
                'reason' => '官网已有相同 Tracking，但物流商不一致或无法核验，系统不会覆盖',
                'remote_tracking_conflict' => true,
                'remote_order_status' => (string) ($snapshot['order_status'] ?? ''),
                'verified_at' => $snapshot['checked_at'] ?? now()->toIso8601String(),
            ];
        }
    }

    private function remoteSnapshotCacheKey(string $connection, int $providerOrderId): string
    {
        return 'erp:woo-tracking-snapshot:v1:'.sha1($connection).':'.$providerOrderId;
    }

    private function providerSlugForCarrier(string $carrier): string
    {
        $normalize = static fn (string $value): string => strtolower(trim(preg_replace(
            '/\\s+/', ' ', str_replace(['_', '-'], ' ', $value),
        ) ?? $value));
        $normalized = $normalize($carrier);
        foreach ((array) config('erp_logistics.carriers', []) as $definition) {
            foreach ([...(array) ($definition['aliases'] ?? []), (string) ($definition['value'] ?? '')] as $alias) {
                if ($normalized !== '' && $normalized === $normalize((string) $alias)) {
                    return (string) ($definition['aftership_slug'] ?? '');
                }
            }
        }

        return '';
    }

    /** @param array{status:string,reason:?string}|null $outbox */
    public function currentStatus(?array $outbox, ManualSyncStatus|string|null $manualSyncStatus): array
    {
        // Eloquent casts the canonical status to a backed enum; list projections
        // also accept serialized status strings from older callers.
        if ($manualSyncStatus instanceof ManualSyncStatus) {
            $manualSyncStatus = $manualSyncStatus->value;
        }

        if ($outbox['remote_tracking_conflict'] ?? false) {
            return ['status' => 'conflict', 'reason' => $outbox['reason'] ?? '官网 Tracking 物流商冲突'];
        }
        if ($outbox['verified_remote_tracking'] ?? false) {
            return ['status' => 'completed', 'reason' => null, 'verified_at' => $outbox['verified_at'] ?? null];
        }

        if ($manualSyncStatus === 'completed' && ($outbox['verified_manual'] ?? false)) {
            return ['status' => 'completed', 'reason' => null, 'verified_at' => $outbox['verified_at'] ?? null];
        }
        if ($manualSyncStatus === 'completed' && ($outbox['verified_outbox'] ?? false)
            && filled($outbox['outbox_tracking_number'] ?? null)
            && ($outbox['status_outbox_verified'] ?? false)) {
            // Outbox is only current when its immutable payload matches the current
            // Shipment fingerprint. A historical/manual label is not proof.
            return ['status' => 'completed', 'reason' => null, 'verified_at' => $outbox['verified_at'] ?? null];
        }
        if (($outbox['tracking_verified'] ?? false) && ($outbox['status_outbox_status'] ?? '') === 'completed' && ($outbox['status_outbox_woo_status'] ?? '') === 'processing') {
            return ['status' => 'partial', 'reason' => 'Tracking 已同步；官网订单仍为 processing，等待其他包裹完成'];
        }
        if (($outbox['status'] ?? '') === 'completed' && ! ($outbox['verified_outbox'] ?? false)) {
            return ['status' => 'pending', 'reason' => '历史同步记录与当前 Tracking 不一致，请重新同步官网'];
        }
        if (($outbox['tracking_verified'] ?? false)
            && ($outbox['status_outbox_status'] ?? '') === 'completed'
            && ($outbox['status_outbox_woo_status'] ?? '') === 'processing') {
            return ['status' => 'partial', 'reason' => 'Tracking 已同步；官网订单仍为 processing，等待其他包裹完成'];
        }
        if (($outbox['status'] ?? '') === 'completed' && ($outbox['outbox_tracking_number'] ?? '') !== ''
            && ! ($outbox['status_outbox_verified'] ?? false)) {
            return ['status' => 'pending', 'reason' => 'AfterShip Tracking 已有写回记录，但官网订单状态尚未验证'];
        }
        if (($outbox['verified_outbox'] ?? false)
            && trim((string) ($outbox['outbox_tracking_number'] ?? '')) !== '') {
            return ['status' => 'pending', 'reason' => '官网写回记录与 ERP 同步状态尚未一致，请重新核对'];
        }
        if ($outbox['stale_manual_override'] ?? false) {
            return ['status' => 'pending', 'reason' => $outbox['reason'] ?? null];
        }

        // Canonical manual-sync state must agree before a completed badge is shown.
        if ($manualSyncStatus !== 'completed' && ($outbox['status'] ?? '') === 'completed') {
            return ['status' => 'pending', 'reason' => null];
        }

        return $outbox ?? [
            // A manually changed ManualSync flag is not a remote Woo readback.
            'status' => 'pending',
            'reason' => $manualSyncStatus === 'completed' ? 'ERP 已标记完成，但没有官网写回验证记录' : null,
        ];
    }
}
