<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Application\Contracts\InventoryReservationRepository;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Models\ShipmentAttempt;
use App\Modules\Logistics\Infrastructure\Models\ShipmentItemPackagingSnapshot;
use App\Modules\Orders\Application\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderWorkflowStatusHistory;
use App\Modules\Sync\Application\ManualSyncService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/** An explicit operator override of current fulfillment, regardless of historical state. */
final class WarehouseBatchReturnAllocationService
{
    public function __construct(
        private readonly InventoryReservationRepository $reservations,
        private readonly AuditRecorder $audit,
        private readonly LogisticsStatusSummary $queues,
        private readonly ManualSyncService $manualSync,
    ) {}

    public function returnToAllocation(Order $order, Authenticatable $actor, string $source): void
    {
        abort_unless(in_array($source, ['domestic', 'oversea'], true), 422);

        DB::transaction(function () use ($order, $actor, $source): void {
            $initial = Order::withTrashed()->findOrFail($order->getKey());
            $rootId = (int) ($initial->fulfillment_parent_id ?: $initial->getKey());
            // Lock the whole family, but a split operation may only mutate the selected leg.
            // Whole-family replacement requires the dedicated parent reallocation workflow.
            $family = Order::withTrashed()
                ->where(fn ($query) => $query->whereKey($rootId)->orWhere('fulfillment_parent_id', $rootId))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $root = $family->get($rootId);
            $selected = $family->get((int) $order->getKey());
            abort_unless($root && $selected, 404);
            abort_if($selected->fulfillment_role === Order::SUPERSEDED_FULFILLMENT_ROLE, 409, '重新分仓历史履约不可恢复运营。');
            $family = $family->reject(fn (Order $member) => $member->fulfillment_role === Order::SUPERSEDED_FULFILLMENT_ROLE);
            $split = $root->originalFulfillmentChildren()->exists() || $selected->fulfillment_parent_id !== null;
            abort_if($split && $selected->is($root), 409, '拆单母订单不能直接退回分仓，请在履约发货中处理对应子订单。');
            $targets = collect([$selected]);

            $shipments = Shipment::query()->whereIn('order_id', $targets->pluck('id'))
                ->orderBy('order_id')->lockForUpdate()->get()->keyBy('order_id');
            if ($split) {
                $shipment = $shipments->get($selected->id);
                abort_if($selected->order_status !== OrderStatus::PendingShipment
                    || ($shipment && ($shipment->shipment_status !== 'pending'
                        || filled($shipment->tracking_number) || $shipment->shipped_at !== null))
                    || $selected->shipmentAttempts()->exists()
                    || DB::table('shipout_dispatch_attempts')->where('order_id', $selected->id)->exists()
                    || DB::table('xlwms_dispatch_attempts')->where('order_id', $selected->id)->exists(),
                    409, '该子订单已有实际物流或仓库推送记录，请核对后使用专用履约处理流程。');
            }
            foreach ($targets as $target) {
                $this->resetCurrentAssignment($target, $shipments->get($target->getKey()), $actor, $source,
                    (int) $selected->getKey(), $rootId);
            }

            if ($split) {
                abort_unless(app(\App\Modules\Orders\Application\OrderProgressQuery::class)
                    ->unassignedSplitLeg(Order::query())->whereKey($selected->id)->exists(),
                    409, '子订单未进入独立履约异常队列，已回滚本次变更。');
            } else {
                abort_unless($this->queues->pendingAllocationQuery()->whereKey($rootId)->exists(),
                    409, '退回后订单无法进入实际待分仓队列，已回滚本次变更。');
            }
        });
    }

    private function resetCurrentAssignment(
        Order $order,
        ?Shipment $shipment,
        Authenticatable $actor,
        string $source,
        int $selectedId,
        int $rootId,
    ): void {
        $before = [
            'workflow_status' => $order->workflow_status,
            'routing_warehouse_code' => $order->routing_warehouse_code,
            'order_status' => $order->order_status?->value,
            'archived_at' => $order->archived_at?->toISOString(),
            'operational_archived_at' => $order->operational_archived_at?->toISOString(),
            'deleted_at' => $order->deleted_at?->toISOString(),
            'shipment_status' => $shipment?->shipment_status,
            'shipped_at' => $shipment?->shipped_at?->toISOString(),
            'warehouse_id' => $shipment?->warehouse_id,
            'carrier' => $shipment?->carrier,
            'service' => $shipment?->service,
            'tracking_number' => $shipment?->tracking_number,
        ];
        if ($reservation = $this->reservations->activeForOrder((int) $order->getKey())) {
            $this->reservations->release((int) $reservation['id'], (int) $actor->getAuthIdentifier(),
                'warehouse_batch_force_return_allocation');
        }

        if ($shipment) {
            // Keep real dispatch, packaging and carrier-billing evidence as
            // an immutable shipment attempt before resetting the live draft.
            if ($shipment->shipment_status !== 'pending' || $shipment->shipped_at !== null) {
                $packaging = ShipmentItemPackagingSnapshot::query()->where('shipment_id', $shipment->getKey())->get()->toArray();
                $billing = $shipment->billingEvidence()->first();
                ShipmentAttempt::query()->create([
                    ...$shipment->only([
                        'shipment_status', 'warehouse_id', 'warehouse_selection_source', 'carrier', 'service',
                        'carrier_selection_source', 'tracking_number', 'shipped_at', 'logistics_note', 'handled_by',
                    ]),
                    'order_id' => $order->getKey(), 'shipment_id' => $shipment->getKey(),
                    'attempt_number' => (int) $order->shipmentAttempts()->max('attempt_number') + 1,
                    'snapshotted_by' => $actor->getAuthIdentifier(), 'snapshotted_at' => now(),
                    'evidence_snapshot' => [
                        'packaging' => $packaging, 'billing' => $billing?->toArray(),
                        'force_return_allocation' => ['source' => $source, 'selected_order_id' => $selectedId],
                    ],
                ]);
                ShipmentItemPackagingSnapshot::query()->where('shipment_id', $shipment->getKey())->delete();
                $billing?->delete();
            }
            $shipment->forceFill([
                'shipment_status' => 'pending', 'shipped_at' => null,
                'warehouse_id' => null, 'warehouse_selection_source' => null,
                'carrier' => null, 'service' => null, 'carrier_service_id' => null,
                'carrier_selection_source' => null, 'handled_by' => null,
                'tracking_number' => null,
            ])->save();
        }
        // Keep the ERP-only manual website-sync flag aligned with the newly
        // pending shipment. This does not send a WooCommerce request.
        $this->manualSync->reopen($order, $actor, '批量强退待分仓');
        $order->overseaWarehouseReview?->forceFill([
            'status' => 'pending', 'progress' => 'pending_review',
            'submitted_warehouse_id' => null, 'reviewed_warehouse_id' => null,
            'reviewed_by' => null, 'reviewed_at' => null,
        ])->save();
        $order->operationalMarkers()->whereIn('marker', [
            'domestic_reviewed', 'domestic_stockout', 'stockout',
        ])->delete();
        $order->fulfillmentAllocation?->forceFill(['status' => 'rejected'])->save();

        $previousStatus = $order->workflow_status;
        $previousRoute = $order->routing_warehouse_code;
        $order->forceFill([
            'order_status' => OrderStatus::PendingShipment,
            'workflow_status' => 'pending_allocation',
            'workflow_revision' => (int) $order->workflow_revision + 1,
            'routing_warehouse_code' => 'UNASSIGNED',
            'routing_revision' => (int) $order->routing_revision + 1,
            'archived_at' => null, 'operational_archived_at' => null,
            'operational_archived_by' => null, 'deleted_at' => null,
        ])->save();
        OrderWorkflowStatusHistory::query()->create([
            'order_id' => $order->getKey(), 'actor_id' => $actor->getAuthIdentifier(),
            'from_status' => $previousStatus, 'to_status' => 'pending_allocation',
            'revision' => (int) $order->workflow_revision,
        ]);
        DB::table('order_warehouse_route_histories')->insert([
            'order_id' => $order->getKey(), 'actor_id' => $actor->getAuthIdentifier(),
            'from_code' => $previousRoute, 'to_code' => 'UNASSIGNED',
            'revision' => (int) $order->routing_revision, 'created_at' => now(),
        ]);
        $this->audit->record(
            AuditActor::user($actor, AuditSource::Web), 'logistics.bulk_return_allocation',
            Order::class, $order->getKey(), $before,
            [
                'source' => $source, 'selected_order_id' => $selectedId, 'pending_allocation_root_id' => $rootId,
                'workflow_status' => 'pending_allocation', 'routing_warehouse_code' => 'UNASSIGNED',
                'warehouse_id' => null, 'shipment_status' => 'pending', 'shipped_at' => null,
                'carrier' => null, 'service' => null, 'tracking_number' => null,
            ], 'warehouse_batch_force_return_allocation',
        );
    }
}
