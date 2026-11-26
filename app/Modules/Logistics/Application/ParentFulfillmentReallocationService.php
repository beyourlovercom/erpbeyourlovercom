<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Application\Contracts\InventoryReservationRepository;
use App\Modules\Logistics\Infrastructure\Models\FulfillmentAllocation;
use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Application\KolOrderAccessScope;
use App\Modules\Orders\Application\OrderAuxiliaryStatusService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderWorkflowStatusHistory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/** Explicit, pre-dispatch-only reset of a failed original split allocation. */
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
            foreach ($family as $member) {
                $shipment = $shipments->get($member->id);
                $this->assertNoDispatch($member, $shipment, $member->is($root));
            }
            // Do not archive a live parent shipment. A split mother may only
            // have an empty pending draft after a prior warehouse rejection.
            $parentShipment = $shipments->get($root->id);
            abort_if($parentShipment && $parentShipment->warehouse_id !== null, 409, '母订单仍有独立发货仓，请先核对。');
            // Lock reservation rows before testing committed state. A concurrent
            // commit must either finish before this check or observe the release.
            $reservations = DB::table('inventory_reservations')->whereIn('order_id', $ids)->orderBy('id')->lockForUpdate()->get();
            abort_if($reservations->contains(fn ($reservation) => ! in_array($reservation->status, ['reserved', 'released', 'expired'], true)),
                409, '存在已扣减或未核实的库存记录，不能重新分仓。');
            abort_if(DB::table('procurement_orders')->whereIn('source_order_id', $ids)->where('status', '!=', 'cancelled')->exists(),
                409, '存在尚未取消的采购任务，请先处理采购单。');
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

    private const DISPATCH_RISK_WORKFLOW = ['sending', 'sent', 'resent', 'partially_shipped', 'shipped', 'shipping', 'shared', 'arrived', 'completed', 'finished'];

    private function assertNoDispatch(Order $member, ?Shipment $shipment, bool $isRoot): void
    {
        $message = '履约 '.$member->erp_order_id.' 存在真实发货或仓库接单风险，请先核实并撤单，不能直接归档。';
        abort_if(! in_array($member->order_status?->value, ['pending_shipment', 'cancelled'], true)
            || in_array($member->workflow_status, self::DISPATCH_RISK_WORKFLOW, true)
            || $member->workflowStatusHistory()->where(fn ($history) => $history
                ->whereIn('from_status', self::DISPATCH_RISK_WORKFLOW)
                ->orWhereIn('to_status', self::DISPATCH_RISK_WORKFLOW))->exists()
            || $member->manualSync()->whereNotNull('synced_at')->exists()
            || $member->shipmentAttempts()->exists()
            || $member->logisticsReview()->exists()
            || $member->operationalMarkers()->whereIn('marker', ['domestic_reviewed', 'woo_manual_tracking_verified', 'woo_manual_force_completed'])->exists()
            || $member->externalReferences()->when($isRoot, fn ($q) => $q->where('provider', '!=', 'woocommerce'))->exists(), 409, $message);
        if ($shipment) {
            // The canonical exception transition originates in shipped;
            // never treat absence of tracking as proof of non-dispatch.
            abort_if($shipment->shipment_status !== 'pending'
                || filled($shipment->tracking_number) || $shipment->shipped_at !== null
                || $shipment->billingEvidence()->exists() || $shipment->overseasHandlingEvidence()->exists()
                || DB::table('shipment_item_packaging_snapshots')->where('shipment_id', $shipment->id)->exists()
                || DB::table('warehouse_dispatch_events')->where('shipment_id', $shipment->id)->exists(), 409, $message);
            // Manual exporters have no reliable per-order download ledger.
            // Never reset routes without a mandatory warehouse review gate.
            $code = strtoupper((string) $shipment->warehouse?->code);
            // The domestic ready query also accepts legacy null workflow. A
            // factory segment must positively be in a pre-export phase.
            abort_if($code === 'FACTORY' && ! in_array($member->workflow_status,
                ['pending_review', 'pending_allocation', 'out_of_stock'], true), 409, $message);
            abort_if($shipment->warehouse_id !== null && ! in_array($code, ['FACTORY', 'US-NY', 'US-LA', 'US-SM', 'DE', 'CA'], true), 409, $message);
        }
        foreach (['shipout_dispatch_attempts', 'shipout_production_batch_items', 'shipout_oms_readbacks',
            'shipout_label_stages', 'xlwms_dispatch_attempts', 'xlwms_de_dispatch_batch_items', 'warehouse_dispatch_states'] as $table) {
            abort_if(DB::table($table)->where('order_id', $member->id)->exists(), 409, $message);
        }
        abort_if(DB::table('xlwms_reshipments')->where('original_order_id', $member->id)->exists(), 409, $message);
        $review = $member->overseaWarehouseReview()->first();
        abort_if($review?->status === 'approved'
            || DB::table('audit_records')->where('entity_type', Order::class)->where('entity_id', (string) $member->id)
                ->whereIn('action', ['domestic.review_approve', 'domestic.review_restock'])->exists()
            || ($review && DB::table('audit_records')->where('entity_type', OverseaWarehouseReview::class)->where('entity_id', (string) $review->id)
                ->whereIn('action', ['oversea.review_approve', 'oversea.review_restock'])->exists()), 409, $message);
    }
}
