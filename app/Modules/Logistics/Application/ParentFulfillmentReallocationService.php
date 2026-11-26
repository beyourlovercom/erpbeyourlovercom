<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Application\Contracts\InventoryReservationRepository;
use App\Modules\Logistics\Infrastructure\Models\FulfillmentAllocation;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Application\KolOrderAccessScope;
use App\Modules\Orders\Application\OrderAuxiliaryStatusService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderWorkflowStatusHistory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/** Explicit reset of a failed original split allocation using the tracking-number gate. */
final class ParentFulfillmentReallocationService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly InventoryReservationRepository $reservations,
        private readonly FulfillmentPaymentGate $payment,
        private readonly LogisticsStatusSummary $queues,
        private readonly OrderAuxiliaryStatusService $auxiliary,
    ) {}

    public static function token(Order $order, ?FulfillmentAllocation $allocation): string
    {
        return hash('sha256', json_encode([
            (int) $order->id, (int) $order->workflow_revision, (int) $order->routing_revision,
            $allocation?->id, $allocation?->status, $allocation?->plan_snapshot,
            $allocation?->manual_assignments, $allocation?->submitted_at?->toISOString(),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{archived_count:int,workflow_revision:int} */
    public function reset(Order $order, string $token, Authenticatable $actor): array
    {
        return DB::transaction(function () use ($order, $token, $actor): array {
            $root = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            KolOrderAccessScope::authorize($actor, $root);
            abort_if($root->fulfillment_parent_id !== null || $root->isOperationallyArchived()
                || $root->archived_at !== null, 409, '只能重新分仓未归档母订单。');
            abort_unless($root->order_status?->value === 'pending_shipment', 409, '母订单不是待发货状态。');
            $this->payment->assertAllowed($root);
            $allocation = $root->fulfillmentAllocation()->lockForUpdate()->first();
            abort_unless(hash_equals(self::token($root, $allocation), $token), 409, '分仓方案已变化，请刷新后重新确认。');
            $children = $root->activeFulfillmentChildren()->orderBy('id')->lockForUpdate()->get();
            abort_if($children->isEmpty(), 409, '没有需要归档的当前子订单，请直接分仓。');
            abort_if($children->contains(fn (Order $child) => ! in_array($child->fulfillment_role, [null, 'original'], true)), 409,
                '存在补发履约，请先人工核对，不能整单重新分仓。');
            $family = collect([$root])->concat($children);
            $ids = $family->pluck('id')->all();
            $shipments = Shipment::query()->whereIn('order_id', $ids)->orderBy('order_id')->lockForUpdate()->get()->keyBy('order_id');
            // Owner business rule: an existing tracking number requires manual
            // reconciliation; a historical review or dispatch attempt alone does not.
            // Check the entire family before any writes, including historical tracking
            // retained by shipment attempts. Archival never cancels a remote OMS order.
            foreach ($family as $member) {
                $shipment = $shipments->get($member->id);
                $attempts = $member->shipmentAttempts()->lockForUpdate()->get(['id', 'tracking_number']);
                abort_if(filled($shipment?->tracking_number)
                    || $attempts->contains(fn ($attempt) => filled($attempt->tracking_number)), 409,
                    '履约 '.$member->erp_order_id.' 已有物流单号，请人工确认是否已发货，并手工调整母订单。不能自动归档。');
            }
            $parentShipment = $shipments->get($root->id);
            abort_if($parentShipment && $parentShipment->warehouse_id !== null, 409, '母订单仍有独立发货仓，请先核对。');
            // Release only currently reserved stock. Never reverse committed stock or
            // alter historical procurement/dispatch evidence based on missing tracking.
            $reservations = DB::table('inventory_reservations')->whereIn('order_id', $ids)->orderBy('id')->lockForUpdate()->get();
            // All validation is complete. Release reservations via canonical
            // repository and archive only the old children, never delete them.
            foreach ($reservations->where('status', 'reserved') as $reservation) {
                $released = $this->reservations->release((int) $reservation->id, (int) $actor->getAuthIdentifier(), 'parent_fulfillment_reallocation');
                $this->audit->record(AuditActor::user($actor, AuditSource::Web), 'inventory.reservation.released',
                    \App\Modules\Inventory\Infrastructure\Models\InventoryReservation::class, $reservation->id,
                    ['status' => 'reserved'], $released, 'parent_fulfillment_reallocation');
            }
            $before = [
                'workflow_status' => $root->workflow_status,
                'routing_warehouse_code' => $root->routing_warehouse_code,
                'allocation' => $allocation?->toArray(),
                'parent_shipment' => $parentShipment?->toArray(),
                'children' => $children->map(fn (Order $child) => $child->only([
                    'id', 'erp_order_id', 'fulfillment_suffix', 'fulfillment_role',
                    'operational_archived_at', 'operational_archived_by',
                ]))->all(),
            ];
            foreach ($children as $child) {
                $child->forceFill([
                    'fulfillment_role' => Order::SUPERSEDED_FULFILLMENT_ROLE,
                    'operational_archived_at' => now(),
                    'operational_archived_by' => $actor->getAuthIdentifier(),
                ])->save();
                $this->audit->record(
                    AuditActor::user($actor, AuditSource::Web), 'fulfillment.child_superseded',
                    Order::class, $child->id, ['fulfillment_role' => 'original'],
                    ['fulfillment_role' => Order::SUPERSEDED_FULFILLMENT_ROLE, 'parent_id' => $root->id],
                    'parent_fulfillment_reallocation',
                );
            }
            if ($parentShipment) {
                $parentShipment->forceFill([
                    'warehouse_id' => null, 'warehouse_selection_source' => null,
                    'carrier' => null, 'service' => null, 'carrier_service_id' => null,
                    'carrier_selection_source' => null, 'handled_by' => null,
                ])->save();
            }
            foreach ($root->items()->lockForUpdate()->get() as $item) {
                $item->forceFill([
                    'sku' => $item->source_sku ?: $item->sku,
                    'product_id' => $item->fulfillmentSourceProductId(),
                    'product_summary' => $item->source_product_summary ?? $item->product_summary,
                    'resolved_fulfillment_sku' => null,
                    'final_sku' => null,
                ])->save();
            }
            if ($allocation) {
                $allocation->forceFill([
                    'status' => 'rejected', 'plan_snapshot' => null,
                    'manual_assignments' => null, 'recommended_plan_key' => null,
                    'selected_plan_key' => null, 'decision_note' => null,
                    'submitted_at' => null, 'submitted_by' => null,
                ])->save();
            }
            $root->operationalMarkers()->whereIn('marker', ['domestic_reviewed', 'domestic_stockout', 'stockout'])->delete();
            $root->overseaWarehouseReview?->forceFill([
                'status' => 'pending', 'progress' => 'pending_review',
                'submitted_warehouse_id' => null, 'reviewed_warehouse_id' => null,
                'reviewed_by' => null, 'reviewed_at' => null, 'note' => null,
            ])->save();
            $this->auxiliary->preserveLegacy($root);
            $revision = (int) $root->workflow_revision + 1;
            $routeRevision = (int) $root->routing_revision + 1;
            $root->forceFill([
                'workflow_status' => 'pending_allocation', 'workflow_revision' => $revision,
                'routing_warehouse_code' => 'UNASSIGNED', 'routing_revision' => $routeRevision,
                'domestic_reject_reason' => null, 'domestic_rejected_by' => null, 'domestic_rejected_at' => null,
            ])->save();
            OrderWorkflowStatusHistory::query()->create([
                'order_id' => $root->id, 'actor_id' => $actor->getAuthIdentifier(),
                'from_status' => $before['workflow_status'], 'to_status' => 'pending_allocation', 'revision' => $revision,
            ]);
            DB::table('order_warehouse_route_histories')->insert([
                'order_id' => $root->id, 'actor_id' => $actor->getAuthIdentifier(),
                'from_code' => $before['routing_warehouse_code'], 'to_code' => 'UNASSIGNED',
                'revision' => $routeRevision, 'created_at' => now(),
            ]);
            $this->audit->record(
                AuditActor::user($actor, AuditSource::Web), 'fulfillment.parent_reallocation_reset',
                Order::class, $root->id, $before,
                ['archived_child_ids' => $children->pluck('id')->all(), 'workflow_status' => 'pending_allocation',
                    'workflow_revision' => $revision], 'parent_fulfillment_reallocation',
            );
            abort_unless($this->queues->pendingAllocationQuery()->whereKey($root->id)->exists(), 409,
                '母订单无法返回待分仓，已回滚全部操作。');

            return ['archived_count' => $children->count(), 'workflow_revision' => $revision];
        });
    }

}
