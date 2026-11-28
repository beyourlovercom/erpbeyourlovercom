<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\SyncOrchestrationService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/** Current local sync failures only; no network IO in list/count predicates. */
final class WebsiteSyncException
{
    public static function forShipment($shipments): void
    {
        $shipments->where('shipments.shipment_status', 'shipped')
            ->whereHas('order', fn ($o) => $o->where(function ($root): void {
                $root->where('source_type', 'woocommerce')
                    ->orWhereHas('fulfillmentParent', fn ($p) => $p->where('source_type', 'woocommerce'));
            }))
            ->where(function ($issues): void {
                $issues->whereHas('order.manualSync', fn ($ms) => $ms->whereIn('website_sync_status', ['failed', 'returned', 'needs_confirmation']))
                    ->orWhereExists(function ($failed): void {
                        $failed->selectRaw('1')->from('sync_outbox as sync_failed')
                            ->where('sync_failed.operation', 'woocommerce.tracking_outbound')
                            ->where('sync_failed.aggregate_type', 'shipment')
                            ->whereColumn('sync_failed.aggregate_id', 'shipments.id')
                            ->whereColumn('sync_failed.payload->tracking_number', 'shipments.tracking_number')
                            ->whereNotExists(fn ($new) => $new->selectRaw('1')->from('sync_outbox as sync_new')
                                ->whereColumn('sync_new.aggregate_id', 'sync_failed.aggregate_id')
                                ->whereColumn('sync_new.operation', 'sync_failed.operation')
                                ->whereColumn('sync_new.id', '>', 'sync_failed.id'))
                            ->whereNotExists(fn ($verified) => $verified->selectRaw('1')->from('order_operational_markers as sync_verified')
                                ->join('manual_syncs as sync_manual', 'sync_manual.order_id', '=', 'sync_verified.order_id')
                                ->whereColumn('sync_verified.order_id', 'shipments.order_id')
                                ->where('sync_verified.marker', 'woo_manual_tracking_verified')
                                ->where('sync_manual.website_sync_status', 'completed')
                                ->whereColumn('sync_verified.marked_at', '>=', 'sync_failed.updated_at'));
                        self::failure($failed);
                    })->orWhereExists(function ($failed): void {
                        $failed->selectRaw('1')->from('sync_outbox as sync_failed')
                            ->crossJoin('orders as sync_order')->whereColumn('sync_order.id', 'shipments.order_id')
                            ->where('sync_failed.operation', 'woocommerce.order_status_outbound')
                            ->where('sync_failed.aggregate_type', 'order')
                            ->whereRaw('sync_failed.aggregate_id = COALESCE(sync_order.fulfillment_parent_id, sync_order.id)')
                            ->whereNotExists(fn ($new) => $new->selectRaw('1')->from('sync_outbox as sync_new')
                                ->whereColumn('sync_new.aggregate_id', 'sync_failed.aggregate_id')
                                ->whereColumn('sync_new.operation', 'sync_failed.operation')
                                ->whereColumn('sync_new.id', '>', 'sync_failed.id'));
                        self::failure($failed);
                    });
            });
    }

    private static function failure($query): void
    {
        $query->where(fn ($f) => $f->where('sync_failed.status', 'failed')
            ->orWhere(fn ($retry) => $retry->where('sync_failed.status', 'pending')
                ->whereNotNull('sync_failed.last_error_code')->where('sync_failed.last_error_code', '!=', '')));
    }

    public static function exists(int $id): bool
    {
        $q = Shipment::query()->where('order_id', $id);
        self::forShipment($q);

        return $q->exists();
    }

    public static function projectedFailure(Order $order): bool
    {
        $shipment = $order->shipment;
        if (! $shipment || $shipment->shipment_status !== 'shipped' || blank($shipment->tracking_number)) {
            return false;
        }
        $root = $order->fulfillment_parent_id ? $order->fulfillmentParent : $order;
        $source = $root?->source_type;
        if (($source instanceof \BackedEnum ? $source->value : $source) !== 'woocommerce') {
            return false;
        }
        $projection = app(LogisticsTrackingListStatus::class);
        $states = $projection->forShipments([(int) $shipment->id]);
        $state = $projection->currentStatus($states[$shipment->id] ?? null, $order->manualSync?->website_sync_status);

        return in_array($state['status'], ['failed', 'conflict', 'retry_wait'], true);
    }

    public function recover(Order $order, Authenticatable $actor): void
    {
        abort_unless(app(LogisticsTrackingPushAccess::class)->allows($actor), 403, '当前账号没有官网物流同步权限，异常已保留。');
        if ((string) config('tracking_sync_v2.manual_mode') === 'canary') {
            $ids = array_map('intval', array_filter(explode(',', (string) config('tracking_sync_v2.manual_canary_order_ids', '')), fn ($v) => ctype_digit(trim($v))));
            abort_unless(in_array((int) $order->id, $ids, true), 403, '此订单不在官网同步授权范围。');
        }
        abort_unless($order->shipment?->shipment_status === 'shipped' && filled($order->shipment?->carrier)
            && filled($order->shipment?->tracking_number) && $order->shipment?->shipped_at !== null, 409, '缺少真实发货信息，请补齐后再解除同步异常。');
        try {
            $run = app(SyncOrchestrationService::class)->trackingSelected([(int) $order->id], $actor, recoverFailure: true);
        } catch (\DomainException $e) {
            abort(409, $e->getMessage());
        } catch (\Throwable) {
            abort(409, '官网同步未完成，异常已保留，请查看同步记录。');
        }
        if ((int) $run->succeeded_items !== 1 || (int) $run->failed_items || (int) $run->conflict_items
            || (int) $run->skipped_items || (int) $run->retrying_items) {
            $problem = DB::table('sync_run_items')->where('sync_run_id', $run->id)
                ->whereIn('status', ['skipped', 'failed', 'conflict', 'retrying'])->first(['result_code', 'error_code']);
            abort(409, '官网同步仍未完成，异常已保留。'.($problem ? '原因：'.($problem->error_code ?: $problem->result_code) : ''));
        }
    }
}
