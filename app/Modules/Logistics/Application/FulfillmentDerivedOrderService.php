<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderSourceType;
use App\Modules\Orders\Application\OrderStatus;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

final class FulfillmentDerivedOrderService
{
    public function __construct(private readonly ShipmentService $shipments) {}

    /** @param array<string,mixed> $plan @return list<array<string,mixed>> */
    public function materialize(Order $parent, array $plan, Authenticatable $actor): array
    {
        $segments = collect($plan['segments'] ?? [])->values();
        abort_if($segments->isEmpty() || $segments->count() > 2, 422, '履约方案必须包含 1–2 个发货段');

        if ($segments->count() === 1) {
            $segment = $segments->first();
            $this->clearPendingChildren($parent);
            $this->assignShipment($parent, $segment, $actor);

            return [$this->summary($parent->fresh(['items', 'shipment.warehouse']), $segment, false)];
        }

        abort_if(blank($parent->erp_order_id), 422, '原订单缺少 ERP 订单号，不能生成拆分订单');
        abort_if(strlen((string) $parent->erp_order_id) > 63, 422, '原订单号过长，不能追加拆单后缀');
        $this->assertParentShipmentMayBeReplaced($parent);
        $parent->shipment?->delete();

        $children = collect();
        foreach ($segments as $index => $segment) {
            $suffix = chr(ord('A') + $index);
            $children->push($this->upsertChild($parent, $suffix, $segment, $actor));
        }

        $keepSuffixes = $children->pluck('fulfillment_suffix')->all();
        $parent->fulfillmentChildren()->whereNotIn('fulfillment_suffix', $keepSuffixes)->get()->each(fn (Order $child) => $this->deletePendingChild($child));

        return $children->map(function (Order $child) use ($segments): array {
            $index = ord((string) $child->fulfillment_suffix) - ord('A');
            return $this->summary($child->fresh(['items', 'shipment.warehouse']), $segments->get($index) ?? [], true);
        })->values()->all();
    }

    private function upsertChild(Order $parent, string $suffix, array $segment, Authenticatable $actor): Order
    {
        $child = Order::query()->firstOrNew([
            'fulfillment_parent_id' => $parent->getKey(),
            'fulfillment_suffix' => $suffix,
        ]);
        if ($child->exists) $this->assertChildMayBeRebuilt($child);

        $child->forceFill([
            'customer_id' => $parent->customer_id,
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

        if ($parent->address) {
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
            $child->items()->create($source->only(['product_id', 'product_summary', 'sku', 'quantity', 'unit_price', 'line_revenue']));
        }

        $this->assignShipment($child->fresh(['items', 'shipment']), $segment, $actor);
        return $child->fresh(['items', 'shipment.warehouse']);
    }

    private function assignShipment(Order $order, array $segment, Authenticatable $actor): void
    {
        $warehouseId = (int) ($segment['warehouse_id'] ?? 0);
        abort_if($warehouseId <= 0, 422, '履约方案缺少有效仓库');
        $this->shipments->saveDraft($order, [
            'warehouse_id' => $warehouseId,
            'warehouse_selection_source' => 'allocation',
        ], $actor);
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
        $parent->fulfillmentChildren()->get()->each(fn (Order $child) => $this->deletePendingChild($child));
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
            'items' => $order->items->map(fn ($item): array => [
                'sku' => $item->sku,
                'product_summary' => $item->product_summary,
                'quantity' => (int) $item->quantity,
            ])->values()->all(),
        ];
    }
}
