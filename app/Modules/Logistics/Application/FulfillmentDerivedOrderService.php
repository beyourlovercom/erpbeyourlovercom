<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderSourceType;
use App\Modules\Orders\Application\OrderStatus;
use App\Modules\Orders\Application\OrderWorkflowStatusService;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderWorkflowStatusHistory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

final class FulfillmentDerivedOrderService
{
    private const MAX_SEGMENTS = 26;

    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly WarehouseFinalSkuResolver $finalSkus,
        private readonly OrderWorkflowStatusService $workflow,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array<string,mixed> $plan @return list<array<string,mixed>> */
    public function materialize(Order $parent, array $plan, Authenticatable $actor): array
    {
        $segments = collect($plan['segments'] ?? [])->values();
        abort_if($segments->isEmpty() || $segments->count() > self::MAX_SEGMENTS, 422, '履约方案必须包含 1–26 个发货段');

        if ($segments->count() === 1) {
            $segment = $segments->first();
            $this->assertParentShipmentMayBeReplaced($parent);
            $this->clearPendingChildren($parent);
            $this->materializeSingleOrderItems($parent, $segment);
            $this->assignShipment($parent, $segment, $actor);
            $this->resetForAllocationReview($parent, $actor);

            return [$this->summary($parent->fresh(['items', 'shipment.warehouse']), $segment, false)];
        }

        abort_if(blank($parent->erp_order_id), 422, '原订单缺少 ERP 订单号，不能生成拆分订单');
        abort_if(strlen((string) $parent->erp_order_id) > 63, 422, '原订单号过长，不能追加拆单后缀');
        $this->assertParentShipmentMayBeReplaced($parent);
        $this->restoreSourceItems($parent);
        $parent->load('items');
        $parent->shipment?->delete();

        $children = collect();
        $generation = $this->allocationGeneration($parent, $segments->count());
        foreach ($segments as $index => $segment) {
            $suffix = FulfillmentOriginalIdentity::suffix($index, $generation);
            abort_if(strlen((string) $parent->erp_order_id.$suffix) > 64, 422, '原订单号过长，不能追加新履约编号');
            $children->push($this->upsertChild($parent, $suffix, $segment, $actor));
        }

        // Parent is a financial/source record in split fulfillment; preserve source
        // SKU while exposing the selected fulfillment resolution for audit.
        foreach ($segments as $segment) {
            foreach ($segment['items'] ?? [] as $planned) {
                $item = $parent->items->firstWhere('id', (int) ($planned['order_item_id'] ?? 0));
                if ($item) $item->forceFill([
                    'source_sku' => $item->source_sku ?: $item->sku,
                    'source_product_id' => $planned['source_product_id'] ?? $item->fulfillmentSourceProductId(),
                    'source_product_summary' => $item->source_product_summary ?? $item->product_summary ?? '',
                    'resolved_fulfillment_sku' => $planned['resolved_fulfillment_sku'] ?? $item->sku,
                    'final_sku' => $this->finalSkus->resolve(
                        (int) ($planned['resolved_product_id'] ?? $item->product_id),
                        $segment['source_code'] === 'OUTSOURCE' ? 'FACTORY' : (string) $segment['source_code'],
                        (string) ($planned['resolved_fulfillment_sku'] ?? $item->sku),
                    ),
                ])->save();
            }
        }

        // The source parent is not a shipping segment. Let its visible phase
        // follow the children instead of retaining a stale staff work label.
        $this->clearSplitParentWorkflowStatus($parent, $actor);
        $keepSuffixes = $children->pluck('fulfillment_suffix')->all();
        $parent->originalFulfillmentChildren()->whereNotIn('fulfillment_suffix', $keepSuffixes)->get()->each(fn (Order $child) => $this->deletePendingChild($child));

        return $children->map(function (Order $child) use ($segments): array {
            $index = $this->indexForSuffix((string) $child->fulfillment_suffix);
            return $this->summary($child->fresh(['items', 'shipment.warehouse']), $segments->get($index) ?? [], true);
        })->values()->all();
    }

    public function allocationGeneration(Order $parent, int $count): int
    {
        $active = $parent->originalFulfillmentChildren()->pluck('fulfillment_suffix')->filter()->all();
        $occupied = $parent->fulfillmentChildren()->withTrashed()->pluck('fulfillment_suffix')->filter()->all();
        try {
            return FulfillmentOriginalIdentity::forAllocation($active, $occupied, $count);
        } catch (\DomainException $exception) {
            abort(409, $exception->getMessage());
        }
    }

    private function indexForSuffix(string $suffix): int
    {
        try {
            return FulfillmentOriginalIdentity::index($suffix);
        } catch (\DomainException $exception) {
            abort(422, $exception->getMessage());
        }
    }

    private function upsertChild(Order $parent, string $suffix, array $segment, Authenticatable $actor): Order
    {
        $child = Order::query()->firstOrNew([
            'fulfillment_parent_id' => $parent->getKey(),
            'fulfillment_suffix' => $suffix,
            'fulfillment_role' => 'original',
        ]);
        if ($child->exists) $this->assertChildMayBeRebuilt($child);

        $child->forceFill([
            'customer_id' => $parent->customer_id,
            'kol_id' => $parent->kol_id,
            'internal_order_number' => 'FULF-'.$parent->getKey().'-'.$suffix,
            'erp_order_id' => $parent->erp_order_id.$suffix,
            'source_type' => OrderSourceType::ManualOther,
            'order_kind' => OrderKind::FulfillmentDerived,
            'external_order_number' => null,
            'order_date' => $parent->order_date,
            'order_note' => $parent->order_note,
            'owner_id' => $parent->owner_id,
            'order_status' => OrderStatus::PendingShipment,
            'currency' => $parent->currency,
        ])->save();

        // Rebuilding a pending segment must not erase an address an operator edited on that child.
        if ($parent->address && ! $child->address()->exists()) {
            $child->address()->updateOrCreate([], $parent->address->only([
                'recipient_name', 'phone', 'country', 'province_state', 'city',
                'address_line_1', 'address_line_2', 'postal_code',
            ]));
        }

        $sourceItems = collect($segment['items'] ?? [])->keyBy(fn (array $item): int => (int) ($item['order_item_id'] ?? 0));
        abort_if($sourceItems->isEmpty(), 422, '拆分订单缺少 SKU');
        $parentItems = $parent->items->keyBy('id');
        $child->items()->delete();
        foreach ($sourceItems as $sourceItemId => $segmentItem) {
            $source = $parentItems->get((int) $sourceItemId);
            abort_unless($source, 422, '拆分方案包含不属于原订单的 SKU');
            $resolvedSku = trim((string) ($segmentItem['resolved_fulfillment_sku'] ?? ''));
            $resolvedProductId = isset($segmentItem['resolved_product_id']) && $segmentItem['resolved_product_id'] !== null
                ? (int) $segmentItem['resolved_product_id']
                : $source->product_id;
            $resolvedSku = $this->finalSkus->resolve(
                $resolvedProductId,
                ($segment['source_code'] ?? '') === 'OUTSOURCE' ? 'FACTORY' : (string) ($segment['source_code'] ?? ''),
                $resolvedSku !== '' ? $resolvedSku : (string) ($source->final_sku ?: $source->sku),
            );
            $resolvedSummary = trim((string) ($segmentItem['resolved_product_summary'] ?? ''));
            $child->items()->create([
                'product_id' => $resolvedProductId,
                'source_product_id' => $segmentItem['source_product_id'] ?? $source->fulfillmentSourceProductId(),
                'source_product_summary' => $source->source_product_summary ?? $source->product_summary ?? '',
                'woocommerce_line_item_id' => $source->woocommerce_line_item_id,
                'product_summary' => $resolvedSummary !== '' ? $resolvedSummary : $source->product_summary,
                'sku' => $resolvedSku !== '' ? $resolvedSku : $source->sku,
                'source_sku' => $source->source_sku ?: $source->sku,
                'resolved_fulfillment_sku' => $resolvedSku !== '' ? $resolvedSku : ($source->resolved_fulfillment_sku ?: $source->sku),
                'final_sku' => $resolvedSku,
                'quantity' => $source->quantity,
                'unit_price' => $source->unit_price,
                'line_revenue' => $source->line_revenue,
            ]);
        }

        $this->assignShipment($child->fresh(['items', 'shipment']), $segment, $actor);
        $this->resetForAllocationReview($child, $actor);
        return $child->fresh(['items', 'shipment.warehouse']);
    }

    /** A one-warehouse order is itself the outbound order, not just an allocation preview. */
    private function materializeSingleOrderItems(Order $order, array $segment): void
    {
        $items = collect($segment['items'] ?? []);
        abort_unless($items->count() === $order->items->count(), 422, '单仓履约明细与原订单行数不一致');
        $seen = [];
        foreach ($items as $planned) {
            $item = $order->items->firstWhere('id', (int) ($planned['order_item_id'] ?? 0));
            abort_unless($item && ! isset($seen[$item->id]), 422, '单仓履约商品行身份无效');
            $seen[$item->id] = true;
            $sourceSku = trim((string) ($item->source_sku ?: $item->sku));
            $sourceProductId = $planned['source_product_id'] ?? $item->fulfillmentSourceProductId();
            $resolvedSku = trim((string) ($planned['resolved_fulfillment_sku'] ?? ''));
            $resolvedProductId = (int) ($planned['resolved_product_id'] ?? $sourceProductId);
            $resolvedSku = $this->finalSkus->resolve(
                $resolvedProductId,
                ($segment['source_code'] ?? '') === 'OUTSOURCE' ? 'FACTORY' : (string) ($segment['source_code'] ?? ''),
                $resolvedSku,
            );
            abort_unless($resolvedSku !== '' && $resolvedProductId > 0, 422, '分仓未得到真实履约 SKU 或商品身份');
            $item->forceFill([
                'source_sku' => $sourceSku,
                'source_product_id' => $sourceProductId,
                'source_product_summary' => $item->source_product_summary ?? $item->product_summary ?? '',
                'sku' => $resolvedSku,
                'product_id' => $resolvedProductId,
                'product_summary' => $planned['resolved_product_summary'] ?: ($item->source_product_summary ?: $item->product_summary),
                'resolved_fulfillment_sku' => $resolvedSku,
                'final_sku' => $resolvedSku,
            ])->save();
        }
    }

    /** Restore immutable order-source identity when the non-shipping parent becomes a split. */
    private function restoreSourceItems(Order $parent): void
    {
        foreach ($parent->items as $item) {
            if (! $item->source_sku && ! $item->source_product_id) continue;
            $item->forceFill([
                'sku' => $item->source_sku ?: $item->sku,
                'product_id' => $item->fulfillmentSourceProductId(),
                'product_summary' => $item->source_product_summary ?? $item->product_summary ?? '',
                'resolved_fulfillment_sku' => null,
                'final_sku' => null,
            ])->save();
        }
    }

    /** Factory and five overseas allocations restart review; partner routes stay untouched. */
    private function resetForAllocationReview(Order $order, Authenticatable $actor): void
    {
        // This approval gate belongs only to the domestic factory and the
        // five overseas shipping warehouses. Partner/Amazon flows are separate.
        $warehouseCode = strtoupper(trim((string) $order->fresh('shipment.warehouse')->shipment?->warehouse?->code));
        if (! in_array($warehouseCode, ['FACTORY', 'US-NY', 'US-LA', 'US-SM', 'DE', 'CA'], true)) {
            return;
        }

        $order->operationalMarkers()->whereIn('marker', ['domestic_reviewed', 'domestic_stockout'])->delete();

        $review = OverseaWarehouseReview::query()->where('order_id', $order->id)->first();
        if ($review) {
            $before = ['status' => $review->status, 'reviewed_warehouse_id' => $review->reviewed_warehouse_id];
            $review->forceFill([
                'status' => 'pending',
                'progress' => 'pending_review',
                'note' => null,
                'submitted_warehouse_id' => $order->fresh('shipment')->shipment?->warehouse_id,
                'reviewed_warehouse_id' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ])->save();
            $this->audit->record(
                AuditActor::user($actor, AuditSource::Web),
                'oversea.review_reset',
                OverseaWarehouseReview::class,
                $review->id,
                $before,
                ['status' => 'pending', 'reviewed_warehouse_id' => null],
                'fulfillment_allocation_requires_review',
            );
        }

        $this->workflow->set($order, 'pending_review', (int) $order->workflow_revision, $actor);
    }

    /** A split parent is a non-shipping aggregate, so children own the review phase. */
    private function clearSplitParentWorkflowStatus(Order $parent, Authenticatable $actor): void
    {
        if ($parent->workflow_status === null) {
            return;
        }
        $before = $parent->workflow_status;
        $revision = (int) $parent->workflow_revision + 1;
        $parent->forceFill(['workflow_status' => null, 'workflow_revision' => $revision])->save();
        // The history destination is non-nullable. Record the effective
        // aggregate phase while the persisted override is cleared to null.
        OrderWorkflowStatusHistory::query()->create([
            'order_id' => $parent->id,
            'actor_id' => $actor->getAuthIdentifier(),
            'from_status' => $before,
            'to_status' => 'allocated',
            'revision' => $revision,
        ]);
        $this->audit->record(
            AuditActor::user($actor, AuditSource::Web),
            'order.workflow_status_changed',
            Order::class,
            $parent->id,
            ['workflow_status' => $before],
            ['workflow_status' => null, 'workflow_revision' => $revision],
            'fulfillment_split_parent_aggregation',
        );
    }

    private function assignShipment(Order $order, array $segment, Authenticatable $actor): void
    {
        $warehouseId = (int) ($segment['warehouse_id'] ?? 0);
        abort_if($warehouseId <= 0, 422, '履约方案缺少有效仓库');
        $sourceCode = strtoupper(trim((string) ($segment['source_code'] ?? '')));
        $physicalCode = $sourceCode === 'OUTSOURCE' ? 'FACTORY' : $sourceCode;
        $warehouse = Warehouse::query()->whereKey($warehouseId)->first();
        abort_unless($sourceCode !== '' && $warehouse
            && strtoupper(trim((string) $warehouse->code)) === $physicalCode,
            422, '履约方案所属分仓与真实仓库不一致');

        // The plan is the authorized routing change. Persist it before
        // ShipmentService validates route == physical warehouse. The caller
        // holds the allocation transaction, so failures roll everything back.
        if ($order->routing_warehouse_code !== $sourceCode) {
            $beforeCode = $order->routing_warehouse_code;
            $beforeRevision = (int) $order->routing_revision;
            $beforeWarehouseId = $order->shipment?->warehouse_id;
            $order->forceFill([
                'routing_warehouse_code' => $sourceCode,
                'routing_revision' => $beforeRevision + 1,
            ])->save();
            DB::table('order_warehouse_route_histories')->insert([
                'order_id' => $order->id,
                'actor_id' => $actor->getAuthIdentifier(),
                'from_code' => $beforeCode,
                'to_code' => $sourceCode,
                'revision' => $beforeRevision + 1,
                'created_at' => now(),
            ]);
            $this->audit->record(
                AuditActor::user($actor, AuditSource::Web),
                'order.warehouse_route_changed',
                Order::class,
                $order->id,
                ['code' => $beforeCode, 'revision' => $beforeRevision, 'warehouse_id' => $beforeWarehouseId],
                ['code' => $sourceCode, 'revision' => $beforeRevision + 1, 'warehouse_id' => $warehouseId],
                'fulfillment_allocation_route_sync',
            );
        }
        $attributes = [
            'warehouse_id' => $warehouseId,
            'warehouse_selection_source' => 'allocation',
        ];
        // Factory assignment only selects the warehouse; Liang chooses the
        // real carrier and service after domestic stock approval.
        if (strtoupper(trim((string) ($segment['source_code'] ?? ''))) !== 'FACTORY'
            && filled($segment['carrier'] ?? null)) {
            $attributes['carrier'] = trim((string) $segment['carrier']);
            $attributes['service'] = filled($segment['service'] ?? null) ? trim((string) $segment['service']) : null;
            $attributes['carrier_selection_source'] = (string) ($segment['carrier_selection_source'] ?? 'automatic');
        }
        $this->shipments->saveDraft($order, $attributes, $actor);
    }

    private function assertParentShipmentMayBeReplaced(Order $parent): void
    {
        $shipment = $parent->shipment;
        if (! $shipment) return;
        abort_if($shipment->shipment_status !== 'pending' || filled($shipment->tracking_number) || $shipment->shipped_at !== null, 409, '原订单已经进入实际物流，不能再拆单');
    }

    private function assertChildMayBeRebuilt(Order $child): void
    {
        $shipment = $child->shipment;
        if (! $shipment) return;
        abort_if($shipment->shipment_status !== 'pending' || filled($shipment->tracking_number) || $shipment->shipped_at !== null, 409, "衍生订单 {$child->erp_order_id} 已进入实际物流，不能重建");
    }

    private function clearPendingChildren(Order $parent): void
    {
        $parent->originalFulfillmentChildren()->get()->each(fn (Order $child) => $this->deletePendingChild($child));
    }

    private function deletePendingChild(Order $child): void
    {
        $this->assertChildMayBeRebuilt($child);
        $child->delete();
    }

    /** @return array<string,mixed> */
    private function summary(Order $order, array $segment, bool $derived): array
    {
        return [
            'order_id' => (int) $order->getKey(),
            'erp_order_id' => $order->erp_order_id,
            'derived' => $derived,
            'suffix' => $order->fulfillment_suffix,
            'warehouse_id' => $order->shipment?->warehouse_id,
            'warehouse_code' => $order->shipment?->warehouse?->code ?? ($segment['source_code'] ?? null),
            'warehouse_name' => $order->shipment?->warehouse?->name ?? ($segment['warehouse_name'] ?? null),
            // Allocation estimates can carry a recommended factory provider,
            // but the actual fulfillment record must reflect the empty draft.
            'carrier' => strtoupper((string) ($segment['source_code'] ?? '')) === 'FACTORY'
                ? $order->shipment?->carrier : ($order->shipment?->carrier ?? ($segment['carrier'] ?? null)),
            'service' => strtoupper((string) ($segment['source_code'] ?? '')) === 'FACTORY'
                ? $order->shipment?->service : ($order->shipment?->service ?? ($segment['service'] ?? null)),
            'carrier_selection_source' => strtoupper((string) ($segment['source_code'] ?? '')) === 'FACTORY'
                ? $order->shipment?->carrier_selection_source
                : ($order->shipment?->carrier_selection_source ?? ($segment['carrier_selection_source'] ?? null)),
            'items' => $order->items->map(fn ($item): array => [
                'sku' => WarehouseSkuPresentation::sku($item),
                'source_sku' => $item->source_sku ?: $item->sku,
                'resolved_fulfillment_sku' => $item->resolved_fulfillment_sku ?: $item->sku,
                'final_sku' => $item->final_sku,
                'product_summary' => $item->product_summary,
                'quantity' => (int) $item->quantity,
            ])->values()->all(),
        ];
    }
}
