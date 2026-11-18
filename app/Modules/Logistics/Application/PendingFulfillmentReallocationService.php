<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\FulfillmentAllocation;
use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderWorkflowStatusHistory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final class PendingFulfillmentReallocationService
{
    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly CarrierRoutingPolicy $carrierRouting,
        private readonly AuditRecorder $audit,
        private readonly FulfillmentAllocationStockGate $stockGate,
        private readonly WarehouseFinalSkuResolver $finalSkus,
    ) {}

    public function reallocate(Order $fulfillment, int $warehouseId, Authenticatable $actor, ?string $note = null, bool $stockExceptionAcknowledged = false): Order
    {
        return DB::transaction(function () use ($fulfillment, $warehouseId, $actor, $note, $stockExceptionAcknowledged): Order {
            $child = Order::query()
                ->with(['shipment.warehouse', 'items', 'fulfillmentParent.address', 'fulfillmentParent.fulfillmentAllocation', 'fulfillmentParent.originalFulfillmentChildren.shipment'])
                ->whereKey($fulfillment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            abort_if($child->isOperationallyArchived(), 409, '归档订单不能重新分仓。');

            abort_unless($child->fulfillment_parent_id !== null && (string) ($child->fulfillment_role ?? '') !== 'replacement', 422, '只有原始拆分履约可以单独调整分仓');
            abort_unless($child->fulfillment_suffix !== null, 422, '履约缺少拆分后缀');
            $this->assertPending($child);

            $parent = $child->fulfillmentParent;
            abort_unless($parent, 422, '找不到父订单');
            $siblings = $parent->originalFulfillmentChildren;
            abort_unless($siblings->count() > 1, 409, '单履约订单请使用整单重新分仓');
            // The old partial-flow gate is correct for ordinary segments, but
            // an explicitly reset A/B child must not become impossible to route
            // merely because its sibling has not shipped yet.
            $reactivatedUnassignedChild = $child->workflow_status === 'pending_allocation'
                || ($child->routing_warehouse_code === 'UNASSIGNED'
                    && ($child->shipment === null || $child->shipment->warehouse_id === null));
            abort_unless($reactivatedUnassignedChild
                || $siblings->contains(fn (Order $segment): bool => $segment->getKey() !== $child->getKey() && $this->logisticsStarted($segment)),
                409, '所有履约都尚未进入物流，请使用整单重新分仓');
            $this->assertNoDispatchEvidence($child);

            $allowedCodes = array_map('strtoupper', (array) config('warehouse_master.fulfillment_codes', []));
            $warehouse = Warehouse::query()->whereKey($warehouseId)->where('is_active', true)->first();
            abort_unless($warehouse && in_array(strtoupper((string) $warehouse->code), $allowedCodes, true), 422, '目标仓库不可用于履约分仓');

            $before = [
                'warehouse_id' => $child->shipment?->warehouse_id,
                'warehouse_code' => $child->shipment?->warehouse?->code,
                'carrier' => $child->shipment?->carrier,
                'service' => $child->shipment?->service,
            ];
            abort_if((int) ($child->shipment?->warehouse_id ?? 0) === (int) $warehouse->getKey(), 422, '请选择不同的目标仓库');

            $stockWarnings = $this->stockGate->assertAcknowledged(
                $child,
                $child->items->map(fn ($item): array => [
                    'order_item_id' => (int) $item->id,
                    'source_code' => (string) $warehouse->code,
                ])->all(),
                $stockExceptionAcknowledged,
            );
            $canonicalCode = strtoupper(trim((string) $warehouse->code));
            $route = $canonicalCode === 'FACTORY'
                ? [] : $this->carrierRouting->warehouseRoute($parent->address?->country, $canonicalCode);
            $carrier = trim((string) ($route['carrier'] ?? ''));
            $service = trim((string) ($route['service'] ?? ''));

            // ShipmentService enforces route == physical warehouse. Align the
            // proposed route inside this transaction before saving its draft;
            // any failed guard rolls both changes back together.
            $previousRoute = $child->routing_warehouse_code;
            $child->routing_warehouse_code = $canonicalCode;
            $child->save();
            $updated = $this->shipments->saveDraft($child, [
                'warehouse_id' => (int) $warehouse->getKey(),
                'warehouse_selection_source' => 'partial_reallocation',
                'carrier' => $carrier !== '' ? $carrier : null,
                'service' => $service !== '' ? $service : null,
                'carrier_selection_source' => $carrier !== '' ? 'automatic' : null,
                'logistics_note' => trim((string) $note) !== '' ? trim((string) $note) : $child->shipment?->logistics_note,
            ], $actor);

            foreach ($child->items as $item) {
                $finalSku = $this->finalSkus->resolve(
                    (int) $item->product_id, $canonicalCode,
                    (string) ($item->source_sku ?: $item->sku),
                );
                abort_if($finalSku === '', 422, '新仓库没有有效最终 SKU');
                $item->forceFill([
                    'sku' => $finalSku,
                    'resolved_fulfillment_sku' => $finalSku,
                    'final_sku' => $finalSku,
                ])->save();
            }

            $this->updateAllocationSnapshot($parent, $child, $warehouse, $carrier !== '' ? $carrier : null, $service !== '' ? $service : null, $actor, $note, $stockWarnings);
            $this->syncRoutingAndReview($updated, $warehouse, $actor, $previousRoute);

            $fresh = $updated->fresh(['items', 'shipment.warehouse', 'fulfillmentParent']);
            $this->audit->record(
                AuditActor::user($actor, AuditSource::Web),
                'fulfillment.pending_reallocated',
                Order::class,
                $fresh->getKey(),
                before: $before,
                after: [
                    'warehouse_id' => (int) $warehouse->getKey(),
                    'warehouse_code' => $canonicalCode,
                    'carrier' => $carrier !== '' ? $carrier : null,
                    'service' => $service !== '' ? $service : null,
                    'parent_order_id' => (int) $parent->getKey(),
                    'suffix' => $child->fulfillment_suffix,
                    'operator_note' => trim((string) $note) !== '' ? trim((string) $note) : null,
                    'stock_exception_acknowledged' => $stockWarnings !== [],
                    'stock_exception_warnings' => $stockWarnings,
                ],
                reason: 'partial_pending_fulfillment_reallocation',
            );

            return $fresh;
        });
    }

    /**
     * A recovered child uses the same canonical route and review contract as
     * any other pending fulfillment. Update the route, physical warehouse and
     * parent allocation snapshot in one transaction.
     */
    private function syncRoutingAndReview(Order $child, Warehouse $warehouse, Authenticatable $actor, ?string $fromCode): void
    {
        $code = strtoupper(trim((string) $warehouse->code));
        $fromStatus = $child->workflow_status;
        $child->routing_warehouse_code = $code;
        $child->routing_revision = (int) $child->routing_revision + 1;
        if ($fromStatus === 'pending_allocation') {
            $child->workflow_status = 'pending_review';
            $child->workflow_revision = (int) $child->workflow_revision + 1;
        }
        $child->save();

        OverseaWarehouseReview::query()->where('order_id', $child->getKey())->update([
            'status' => 'pending', 'progress' => 'pending_review',
            'submitted_warehouse_id' => $warehouse->getKey(),
            'reviewed_warehouse_id' => null, 'reviewed_by' => null, 'reviewed_at' => null,
        ]);
        DB::table('order_warehouse_route_histories')->insert([
            'order_id' => $child->getKey(), 'actor_id' => $actor->getAuthIdentifier(),
            'from_code' => $fromCode, 'to_code' => $code,
            'revision' => $child->routing_revision, 'created_at' => now(),
        ]);
        if ($fromStatus === 'pending_allocation') {
            OrderWorkflowStatusHistory::query()->create([
                'order_id' => $child->getKey(), 'actor_id' => $actor->getAuthIdentifier(),
                'from_status' => 'pending_allocation', 'to_status' => 'pending_review',
                'revision' => (int) $child->workflow_revision,
            ]);
        }
    }

    private function assertPending(Order $child): void
    {
        $shipment = $child->shipment;
        abort_if($child->order_status?->value !== 'pending_shipment'
            || ($shipment && ($shipment->shipment_status !== 'pending'
                || filled($shipment->tracking_number) || $shipment->shipped_at !== null)),
            409, '该履约已经进入实际物流，不能调整分仓');
    }

    private function assertNoDispatchEvidence(Order $child): void
    {
        abort_if($child->shipmentAttempts()->exists()
            || DB::table('shipout_dispatch_attempts')->where('order_id', $child->id)->exists(), 409,
            '该履约已有仓库推送记录，请核实仓库是否接单后再重新分仓。');
    }

    private function logisticsStarted(Order $segment): bool
    {
        $shipment = $segment->shipment;
        if (! $shipment) {
            return false;
        }

        return $shipment->shipment_status !== 'pending' || filled($shipment->tracking_number) || $shipment->shipped_at !== null;
    }

    private function updateAllocationSnapshot(
        Order $parent,
        Order $child,
        Warehouse $warehouse,
        ?string $carrier,
        ?string $service,
        Authenticatable $actor,
        ?string $note,
        array $stockWarnings,
    ): void {
        $allocation = FulfillmentAllocation::query()->where('order_id', $parent->getKey())->lockForUpdate()->first();
        if (! $allocation) {
            return;
        }

        $snapshot = is_array($allocation->plan_snapshot) ? $allocation->plan_snapshot : [];
        $segments = array_values((array) ($snapshot['segments'] ?? []));
        $index = ord(strtoupper((string) $child->fulfillment_suffix)[0]) - ord('A');
        if (isset($segments[$index])) {
            $segments[$index]['source_type'] = strtoupper((string) $warehouse->code) === 'FACTORY' ? 'factory' : 'warehouse';
            $segments[$index]['source_code'] = strtoupper(trim((string) $warehouse->code));
            $segments[$index]['warehouse_id'] = (int) $warehouse->getKey();
            $segments[$index]['warehouse_name'] = (string) $warehouse->name;
            $segments[$index]['carrier'] = $carrier;
            $segments[$index]['service'] = $service;
            $segments[$index]['carrier_selection_source'] = $carrier ? 'automatic' : null;
        }
        $snapshot['segments'] = $segments;

        $derived = array_values((array) ($snapshot['derived_orders'] ?? []));
        foreach ($derived as &$row) {
            if (($row['suffix'] ?? null) !== $child->fulfillment_suffix) {
                continue;
            }
            $row['warehouse_id'] = (int) $warehouse->getKey();
            $row['warehouse_code'] = strtoupper(trim((string) $warehouse->code));
            $row['warehouse_name'] = (string) $warehouse->name;
            $row['carrier'] = $carrier;
            $row['service'] = $service;
            $row['carrier_selection_source'] = $carrier ? 'automatic' : null;
        }
        unset($row);
        $snapshot['derived_orders'] = $derived;
        $snapshot['execution_status'] = 'partial_pending_fulfillment_reallocated';
        if ($stockWarnings !== []) {
            $history = (array) ($snapshot['stock_exception_reallocations'] ?? []);
            $history[] = [
                'suffix' => $child->fulfillment_suffix,
                'actor_id' => $actor->getAuthIdentifier(),
                'acknowledged_at' => now()->toIso8601String(),
                'warnings' => $stockWarnings,
            ];
            $snapshot['stock_exception_reallocations'] = $history;
        }
        $signature = json_encode($segments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $snapshot['plan_key'] = 'partial:'.substr(hash('sha256', (string) $signature), 0, 16);

        $manualAssignments = is_array($allocation->manual_assignments) ? $allocation->manual_assignments : null;
        $segmentItemIds = collect($segments[$index]['items'] ?? [])->pluck('order_item_id')->map(fn ($id): int => (int) $id)->all();
        if ($manualAssignments !== null && $segmentItemIds !== []) {
            foreach ($manualAssignments as &$assignment) {
                if (! in_array((int) ($assignment['order_item_id'] ?? 0), $segmentItemIds, true)) {
                    continue;
                }
                $assignment['source_code'] = strtoupper(trim((string) $warehouse->code));
                $assignment['carrier'] = $carrier;
                $assignment['service'] = $service;
                $assignment['carrier_selection_source'] = $carrier ? 'automatic' : null;
            }
            unset($assignment);
        }

        $allocation->forceFill([
            'decision_source' => 'manual',
            'selected_plan_key' => $snapshot['plan_key'],
            'plan_snapshot' => $snapshot,
            'manual_assignments' => $manualAssignments,
            'decision_note' => trim((string) $note) !== '' ? trim((string) $note) : $allocation->decision_note,
            'submitted_by' => $actor->getAuthIdentifier(),
            'submitted_at' => now(),
        ])->save();
    }
}
