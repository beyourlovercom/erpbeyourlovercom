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

    public static function scopeCreated(Builder $query): void
    {
        self::scopeAttempt($query);
        $query->where('shipout_attempt.status', 'acknowledged')
            ->whereNotNull('shipout_attempt.provider_order_id')
            ->where('shipout_attempt.provider_order_id', '!=', '');
    }

    public static function isOmsCreated(?object $attempt): bool
    {
        return $attempt !== null
            && (string) ($attempt->provider_warehouse_id ?? '') === self::MOREPLUS_PROVIDER_WAREHOUSE_ID
            && (string) ($attempt->status ?? '') === 'acknowledged'
            && trim((string) ($attempt->provider_order_id ?? '')) !== '';
    }

    /** @return array{attempted:bool,is_oms_created:bool,status:string,label:string,provider_order_id:?string} */
    public static function from(?object $attempt): array
    {
        $created = self::isOmsCreated($attempt);
        $attempted = $attempt !== null;

        return [
            'attempted' => $attempted,
            'is_oms_created' => $created,
            'status' => $created ? 'oms_created' : ($attempted ? 'requires_reconciliation' : 'pending_dispatch'),
            'label' => $created ? 'OMS 已建单 · 待实际出库'
                : ($attempted ? '提交结果待核对 · 禁止重发' : '待下发'),
            'provider_order_id' => $created ? (string) $attempt->provider_order_id : null,
        ];
    }

    /** @return array{status:string,label:string,detail:string,oms_order_no:?string,last_checked_at:?string,failure_code:?string,can_retry_local_block:bool} */
    public static function warehouseDispatch(?object $attempt, string $shipmentStatus): array
    {
        $state = self::from($attempt);
        $shipped = in_array($shipmentStatus, ['shipped', 'completed'], true);

        return [
            'status' => $shipped ? 'shipped' : $state['status'],
            'label' => $shipped ? '已发货' : $state['label'],
            'detail' => $shipped ? '' : ($state['is_oms_created'] ? '已提交仓库，尚无真实出库 Tracking' : ''),
            'oms_order_no' => $state['provider_order_id'],
            'last_checked_at' => null,
            'failure_code' => null,
            'can_retry_local_block' => false,
        ];
    }
}
