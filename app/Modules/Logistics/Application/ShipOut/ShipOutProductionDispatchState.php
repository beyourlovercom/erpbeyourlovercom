<?php

namespace App\Modules\Logistics\Application\ShipOut;

use Illuminate\Database\Query\Builder;

/**
 * Read-only fulfillment stage derived from the immutable one-shot ledger.
 * OMS creation is independent of Shipment.shipment_status: only real shipped
 * evidence may change shipment/order state or trigger Woo/AfterShip writes.
 */
final class ShipOutProductionDispatchState
{
    public const MOREPLUS_PROVIDER_WAREHOUSE_ID = '1265864414750314497';

    public static function scopeAttempt(Builder $query): void
    {
        $query->selectRaw('1')->from('shipout_dispatch_attempts as shipout_attempt')
            ->whereColumn('shipout_attempt.order_id', 'shipments.order_id')
            ->where('shipout_attempt.provider_warehouse_id', self::MOREPLUS_PROVIDER_WAREHOUSE_ID);
    }

    public static function scopeBatchQuarantined(Builder $query): void
    {
        $query->selectRaw('1')->from('shipout_production_batch_items as q')
            ->whereColumn('q.order_id', 'shipments.order_id')
            ->whereIn('q.status', [
                'skipped', 'uncertain', 'failed', 'provider_cancelled', 'needs_review',
            ]);
    }

    /** SQL predicate against newest sanitized signed OMS GET, never API ACK. */
    public static function scopeLatestOmsState(Builder $query, array $states): void
    {
        $query->selectRaw('1')->from('shipout_oms_readbacks as oms_latest')
            ->whereColumn('oms_latest.order_id', 'shipments.order_id')
            ->whereIn('oms_latest.state', $states)
            ->whereRaw('oms_latest.id = (SELECT MAX(oms_prior.id) FROM shipout_oms_readbacks AS oms_prior WHERE oms_prior.order_id = shipments.order_id)');
    }

    /** A confirmed OMS row is still NOT proof of real physical shipping. */
    public static function scopeCreated(Builder $query): void
    {
        self::scopeLatestOmsState($query, ['confirmed', 'warehouse_awaiting', 'provider_shipped']);
        $query->whereExists(function ($attempt): void {
            self::scopeAttempt($attempt);
            $attempt->where('shipout_attempt.status', 'acknowledged')
                ->whereColumn('shipout_attempt.provider_order_id', 'oms_latest.provider_order_id');
        });
    }

    public static function scopeOmsException(Builder $query): void
    {
        self::scopeLatestOmsState($query, ['cancelled', 'unverified']);
    }

    public static function isOmsCreated(?object $attempt): bool
    {
        return $attempt !== null
            && (string) ($attempt->provider_warehouse_id ?? '') === self::MOREPLUS_PROVIDER_WAREHOUSE_ID
            && (string) ($attempt->status ?? '') === 'acknowledged'
            && trim((string) ($attempt->provider_order_id ?? '')) !== '';
    }

    /** @return array{attempted:bool,is_oms_created:bool,status:string,label:string,provider_order_id:?string} */
    public static function from(?object $attempt, ?object $batchItem = null, ?object $omsReadback = null): array
    {
        $accepted = self::isOmsCreated($attempt);
        $attempted = $attempt !== null;

        // A terminal batch exception overrides an earlier ACK in the list.
        // Never use the ACK itself to claim that OMS accepted the order.
        $latestOmsState = (string) ($omsReadback->state ?? '');
        $quarantine = in_array((string) ($batchItem->status ?? ''), [
            'provider_cancelled', 'needs_review', 'uncertain', 'failed', 'skipped',
        ], true) || in_array($latestOmsState, ['cancelled', 'unverified'], true);
        $code = (string) (($omsReadback->reason_code ?? null) ?: ($batchItem->result_code ?? ''));
        $reason = match ($code) {
            'PROVIDER_DUPLICATE_ORDER' => '海外仓判定重复订单',
            'PROVIDER_EZEESHIP_SHIPPING_ERROR' => 'EzeeShip 运输服务失败',
            default => '订单异常，需人工核对',
        };
        if ($quarantine) {
            return [
                'attempted' => $attempted || $batchItem !== null,
                'is_oms_created' => false,
                'status' => 'oms_quarantined',
                'label' => $reason.' · 已隔离（禁止自动重发）',
                'provider_order_id' => $accepted ? (string) $attempt->provider_order_id : null,
            ];
        }

        if ($accepted && in_array($latestOmsState, ['confirmed', 'warehouse_awaiting', 'provider_shipped'], true)) {
            $status = match ($latestOmsState) {
                'warehouse_awaiting' => 'oms_warehouse_awaiting',
                'provider_shipped' => 'oms_shipped_pending_tracking',
                default => 'oms_confirmed',
            };
            $label = match ($latestOmsState) {
                'warehouse_awaiting' => '已下发 · 待仓库处理（未出库）',
                'provider_shipped' => 'OMS 已发货 · Tracking 待回写 ERP',
                default => '已下发 · OMS 仓库处理中（未出库）',
            };

            return [
                'attempted' => true, 'is_oms_created' => true,
                'status' => $status,
                'label' => $label,
                'provider_order_id' => (string) $attempt->provider_order_id,
            ];
        }

        // The durable attempt ledger only knows the API ACK. One provider
        // readback does not guarantee its async job will not be cancelled.
        return [
            'attempted' => $attempted,
            'is_oms_created' => false,
            'status' => $accepted ? 'oms_ack_pending' : ($attempted ? 'requires_reconciliation' : 'pending_dispatch'),
            'label' => $accepted
                ? ($latestOmsState === 'visible' ? 'OMS 可见 · 下发核实中（禁止重发）'
                    : 'API 已受理 · 下发核实中（禁止重发）')
                : ($attempted ? '提交结果待核对 · 禁止重发' : '待下发'),
            'provider_order_id' => $accepted ? (string) $attempt->provider_order_id : null,
        ];
    }

    /** @return array{status:string,label:string,detail:string,oms_order_no:?string,last_checked_at:?string,failure_code:?string,can_retry_local_block:bool} */
    public static function warehouseDispatch(?object $attempt, string $shipmentStatus, ?object $batchItem = null, ?object $omsReadback = null): array
    {
        $state = self::from($attempt, $batchItem, $omsReadback);
        $shipped = in_array($shipmentStatus, ['shipped', 'completed'], true);

        return [
            'status' => $shipped ? 'shipped' : $state['status'],
            'label' => $shipped ? '已发货' : $state['label'],
            'detail' => $shipped ? '' : ($state['status'] === 'oms_quarantined'
                ? '本单已从自动发货队列隔离，请物流主管核对，不允许直接重发'
                : ($state['status'] === 'oms_ack_pending' ? 'API ACK 非最终业务成功；请通过正式 OMS 只读核实'
                    : ($state['status'] === 'oms_shipped_pending_tracking'
                        ? '官方 OMS status=4 Shipped；须读取仓库真实 Tracking、出库时间和包裹后才可写入 ERP，并交同步模块处理官网'
                        : ''))),
            'oms_order_no' => $state['provider_order_id'],
            'last_checked_at' => $omsReadback->checked_at ?? null,
            'failure_code' => $state['status'] === 'oms_quarantined'
                ? (string) (($omsReadback->reason_code ?? null) ?: ($batchItem->result_code ?? 'SHIPOUT_BATCH_ITEM_QUARANTINED')) : null,
            'can_retry_local_block' => false,
        ];
    }
}
