<?php

namespace App\Modules\Orders\Application;

use App\Modules\Inventory\Application\Contracts\InventoryReservationRepository;
use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Logistics\Application\WarehouseFinalSkuResolver;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Keep the existing single-warehouse fulfillment current without another stock approval. */
final class ManualOrderItemAmendment
{
    public function __construct(
        private readonly InventoryReservationRepository $reservations,
        private readonly WarehouseFinalSkuResolver $finalSkus,
        private readonly ShipmentService $shipments,
    ) {}

    /** The controller holds the order lock and transaction. */
    public function refresh(Order $order, Collection $before, Authenticatable $actor): void
    {
        $shipment = $order->shipment()->with('warehouse')->first();
        $allocation = $order->fulfillmentAllocation()->first();
        $warehouseId = (int) ($shipment?->warehouse_id ?? 0);
        $items = $order->items()->orderBy('id')->get();
        $changedIds = $items->filter(function ($item) use ($before): bool {
            $old = $before->get($item->id);

            return ! $old || (int) $old->product_id !== (int) $item->product_id
                || $old->sku !== $item->sku;
        })->pluck('id')->all();

        if ($warehouseId > 0) {
            foreach ($items->whereIn('id', $changedIds) as $item) {
                // Stock availability is not a prerequisite for a staff edit.
                // A verified warehouse alias, when present, remains authoritative.
                $sku = $this->finalSkus->expected((int) $item->product_id, $warehouseId);
                if (config('warehouse_final_sku.require_verified_mapping')) {
                    abort_if($sku === null, 422, '目标仓库没有经过确认的商品 SKU 映射');
                }
                $item->forceFill([
                    'sku' => $sku ?? $item->sku,
                    'resolved_fulfillment_sku' => $sku ?? $item->sku,
                    'final_sku' => $sku,
                ])->save();
            }
        }

        if ($shipment) {
            $this->shipments->refreshAmendedItemPackaging($order, $shipment, $changedIds);
        }

        $items = $order->items()->orderBy('id')->get();
        if ($allocation && $allocation->status === 'submitted') {
            $plan = $allocation->plan_snapshot ?? [];
            if ($warehouseId > 0 && count($plan['segments'] ?? []) <= 1) {
                $segment = $plan['segments'][0] ?? [];
                $code = (string) ($segment['source_code'] ?? $shipment->warehouse->code);
                $rows = $items->map(fn ($item): array => [
                    'order_item_id' => (int) $item->id,
                    'product_id' => (int) ($item->source_product_id ?: $item->product_id),
                    'sku' => $item->source_sku ?: $item->sku,
                    'source_sku' => $item->source_sku ?: $item->sku,
                    'source_product_id' => (int) ($item->source_product_id ?: $item->product_id),
                    'source_product_summary' => $item->source_product_summary ?: $item->product_summary,
                    'product_summary' => $item->source_product_summary ?: $item->product_summary,
                    'quantity' => (int) $item->quantity,
                    'resolved_product_id' => (int) $item->product_id,
                    'resolved_fulfillment_sku' => $item->resolved_fulfillment_sku ?: $item->sku,
                    'resolved_product_summary' => $item->product_summary,
                ])->all();
                $plan['segments'] = [[
                    ...$segment, 'source_code' => $code, 'warehouse_id' => $warehouseId,
                    'warehouse_name' => $shipment->warehouse->name,
                    'source_type' => in_array($code, ['FACTORY', 'OUTSOURCE'], true) ? 'factory' : 'warehouse',
                    'items' => $rows,
                ]];
                $plan['plan_key'] = 'manual:'.substr(hash('sha256', json_encode($plan['segments'], JSON_THROW_ON_ERROR)), 0, 16);
                $plan['plan_type'] = $code === 'FACTORY' ? 'factory' : 'single';
                $plan['is_split'] = false;
                $plan['shipment_count'] = 1;
                $plan['cost'] = ['status' => 'not_evaluated', 'amount' => null, 'currency' => null, 'source' => null];
                $plan['stock_exception_acknowledgement'] = null;
                $plan['gift_stock_exception_acknowledgement'] = null;
                $plan['derived_orders'] = [[
                    'order_id' => (int) $order->id, 'erp_order_id' => $order->erp_order_id,
                    'derived' => false, 'suffix' => null, 'warehouse_id' => $warehouseId,
                    'warehouse_code' => $shipment->warehouse->code, 'warehouse_name' => $shipment->warehouse->name,
                    'carrier' => $shipment->carrier, 'service' => $shipment->service,
                    'carrier_selection_source' => $shipment->carrier_selection_source,
                    'items' => $items->map(fn ($item): array => [
                        'sku' => $item->sku, 'source_sku' => $item->source_sku ?: $item->sku,
                        'resolved_fulfillment_sku' => $item->resolved_fulfillment_sku ?: $item->sku,
                        'final_sku' => $item->final_sku, 'product_summary' => $item->product_summary,
                        'quantity' => (int) $item->quantity,
                    ])->all(),
                ]];
                $allocation->forceFill([
                    'decision_source' => 'manual', 'selected_plan_key' => $plan['plan_key'],
                    'plan_snapshot' => $plan, 'manual_assignments' => array_map(fn ($row) => [
                        'order_item_id' => $row['order_item_id'], 'source_code' => $code,
                        'carrier' => $shipment->carrier, 'service' => $shipment->service,
                        'resolved_fulfillment_sku' => $row['resolved_fulfillment_sku'],
                        'resolved_product_id' => $row['resolved_product_id'],
                    ], $rows),
                ])->save();
            } elseif ($warehouseId === 0) {
                // A legacy allocation record without a real warehouse cannot authorize dispatch.
                $allocation->forceFill(['status' => 'rejected'])->save();
            }
        }

        $active = $this->reservations->activeForOrder((int) $order->id);
        if (! $active) {
            return;
        }
        $oldRequired = collect($active['items'])->groupBy('product_id')->map(fn ($rows) => (int) $rows->sum('quantity'))->sortKeys()->all();
        $newRequired = $items->groupBy('product_id')->map(fn ($rows) => (int) $rows->sum('quantity'))->sortKeys()->all();
        if ($oldRequired === $newRequired && (int) $active['warehouse_id'] === $warehouseId) {
            return;
        }

        $actorId = (int) $actor->getAuthIdentifier();
        $this->reservations->release((int) $active['id'], $actorId, 'manual_order_item_amendment');
        if ($warehouseId > 0) {
            try {
                DB::transaction(fn () => $this->reservations->reserve((int) $order->id, $warehouseId,
                    'manual-amendment:'.Str::uuid(), null, $actorId));
            } catch (\DomainException $exception) {
                // Do not invent stock or keep a reservation against the wrong SKU.
                Log::notice('order.manual_amendment.reservation_not_recreated', [
                    'order_id' => $order->id, 'warehouse_id' => $warehouseId,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }
    }
}
