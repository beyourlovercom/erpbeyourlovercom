<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Application\Contracts\InventoryReservationRepository;
use App\Modules\Orders\Application\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderWorkflowStatusHistory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/** A physical unassignment, not a cosmetic workflow label change. */
final class WarehouseBatchReturnAllocationService
{
    public function __construct(
        private readonly InventoryReservationRepository $reservations,
        private readonly AuditRecorder $audit,
        private readonly LogisticsStatusSummary $queues,
    ) {}

    public function returnToAllocation(Order $order, Authenticatable $actor, string $source): void
    {
        abort_unless(in_array($source, ['domestic', 'oversea'], true), 422);

        DB::transaction(function () use ($order, $actor, $source): void {
            $locked = Order::query()->with([
                'shipment.warehouse', 'fulfillmentParent', 'originalFulfillmentChildren',
                'fulfillmentAllocation', 'overseaWarehouseReview',
            ])->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            abort_if($locked->trashed() || $locked->archived_at !== null || $locked->isOperationallyArchived(),
                409, '订单已经归档，请先恢复。');

            $physical = strtoupper(trim((string) ($locked->shipment?->warehouse?->code ?? '')));
            $route = strtoupper(trim((string) ($locked->routing_warehouse_code ?? '')));
            $isDomestic = $physical === 'FACTORY' || in_array($route, ['FACTORY', 'OUTSOURCE'], true);
            $overseas = ['US-NY', 'US-LA', 'US-SM', 'DE', 'CA'];
            $isOversea = in_array($physical, $overseas, true) || in_array($route, $overseas, true);
            abort_unless($source === 'domestic' ? $isDomestic : $isOversea,
                409, '订单已移至其他发货仓，请刷新当前列表。');

            // A/B/C segments need their own parent-allocation snapshot update;
            // never silently reset unrelated sibling shipments.
            abort_if($locked->fulfillment_parent_id !== null || $locked->originalFulfillmentChildren->isNotEmpty(),
                409, '该订单存在 A/B/C 拆分履约，不能只重置其中一段的仓库。');

            $shipment = $locked->shipment;
            abort_if($shipment && ($shipment->shipment_status !== 'pending' || $shipment->shipped_at !== null),
                409, '已有真实出库记录，无法清空历史发货仓；请通过补发/重发处理。');

            $before = [
                'workflow_status' => $locked->workflow_status,
                'routing_warehouse_code' => $locked->routing_warehouse_code,
                'order_status' => $locked->order_status?->value,
                'warehouse_id' => $shipment?->warehouse_id,
                'carrier' => $shipment?->carrier,
                'service' => $shipment?->service,
                'staged_tracking_number' => $shipment?->tracking_number,
            ];
            if ($reservation = $this->reservations->activeForOrder((int) $locked->getKey())) {
                $this->reservations->release((int) $reservation['id'], (int) $actor->getAuthIdentifier(),
                    'warehouse_batch_force_return_allocation');
            }
            if ($shipment) {
                $shipment->forceFill([
                    'warehouse_id' => null, 'warehouse_selection_source' => null,
                    'carrier' => null, 'service' => null, 'carrier_service_id' => null,
                    'carrier_selection_source' => null, 'handled_by' => null,
                    'tracking_number' => null,
                ])->save();
            }
            $locked->overseaWarehouseReview?->forceFill([
                'status' => 'pending', 'progress' => 'pending_review',
                'submitted_warehouse_id' => null, 'reviewed_warehouse_id' => null,
                'reviewed_by' => null, 'reviewed_at' => null,
            ])->save();
            $locked->operationalMarkers()->whereIn('marker', [
                'domestic_reviewed', 'domestic_stockout', 'stockout',
            ])->delete();
            $locked->fulfillmentAllocation?->forceFill(['status' => 'rejected'])->save();

            $previousStatus = $locked->workflow_status;
            $previousRoute = $locked->routing_warehouse_code;
            $locked->forceFill([
                'order_status' => OrderStatus::PendingShipment,
                'workflow_status' => 'pending_allocation',
                'workflow_revision' => (int) $locked->workflow_revision + 1,
                'routing_warehouse_code' => 'UNASSIGNED',
                'routing_revision' => (int) $locked->routing_revision + 1,
            ])->save();
            OrderWorkflowStatusHistory::query()->create([
                'order_id' => $locked->getKey(), 'actor_id' => $actor->getAuthIdentifier(),
                'from_status' => $previousStatus, 'to_status' => 'pending_allocation',
                'revision' => (int) $locked->workflow_revision,
            ]);
            DB::table('order_warehouse_route_histories')->insert([
                'order_id' => $locked->getKey(), 'actor_id' => $actor->getAuthIdentifier(),
                'from_code' => $previousRoute, 'to_code' => 'UNASSIGNED',
                'revision' => (int) $locked->routing_revision, 'created_at' => now(),
            ]);

            abort_unless($this->queues->pendingAllocationQuery()->whereKey($locked->getKey())->exists(),
                409, '退回后订单无法进入实际待分仓队列，已回滚本次变更。');
            $this->audit->record(
                AuditActor::user($actor, AuditSource::Web), 'logistics.bulk_return_allocation',
                Order::class, $locked->getKey(), $before,
                [
                    'source' => $source, 'workflow_status' => 'pending_allocation',
                    'routing_warehouse_code' => 'UNASSIGNED', 'warehouse_id' => null,
                    'carrier' => null, 'service' => null, 'staged_tracking_number' => null,
                ], 'warehouse_batch_force_return_allocation',
            );
        });
    }
}
