<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use Illuminate\Support\Carbon;

final class WarehouseShipmentProjection
{
    /** @return array<string,mixed> */
    public function forWarehouse(Warehouse $warehouse, int $limit = 50): array
    {
        $logisticsKey = array_search(
            strtoupper((string) $warehouse->code),
            OverseaWarehouseReviewService::WAREHOUSE_CODE_BY_KEY,
            true,
        );

        $base = Shipment::query()
            ->where('warehouse_id', $warehouse->id)
            ->whereHas('order', fn ($q) => $q
                ->where('order_status', '!=', 'cancelled')
                ->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')));

        $since = Carbon::now()->subDays(30);

        $counts = [
            'total' => (clone $base)->count(),
            'last_30d' => (clone $base)->where('shipped_at', '>=', $since)->count(),
            'today' => (clone $base)->whereDate('shipped_at', Carbon::today())->count(),
            'exceptions' => (clone $base)->where('shipment_status', 'exception')->count(),
            'completed' => (clone $base)->whereIn('shipment_status', ['shipped', 'completed'])->count(),
        ];

        $rows = (clone $base)
            ->with([
                'order.address',
                'order.items.product',
                'warehouse',
                'billingEvidence',
                'overseasHandlingEvidence',
            ])
            ->latest('shipped_at')
            ->latest('id')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->map(function (Shipment $shipment): array {
                $order = $shipment->order;
                $billing = $shipment->billingEvidence;
                $handling = $shipment->overseasHandlingEvidence;

                return [
                    'shipment_id' => (int) $shipment->id,
                    'order_id' => $order ? (int) $order->id : null,
                    'erp_order_id' => $order?->erp_order_id,
                    'order_url' => $order ? "/orders/{$order->id}" : null,
                    'shipment_status' => (string) $shipment->shipment_status,
                    'carrier' => $shipment->carrier,
                    'service' => $shipment->service,
                    'tracking_number' => $shipment->tracking_number,
                    'shipped_at' => $shipment->shipped_at?->toIso8601String(),
                    'destination_country' => $order?->address?->country,
                    'items' => $order?->items->map(fn ($item): array => [
                        'sku' => $item->resolved_fulfillment_sku ?: $item->sku,
                        'name' => $item->product?->name,
                        'quantity' => (int) $item->quantity,
                    ])->values()->all() ?? [],
                    'last_mile_cost' => $billing?->actual_cost !== null ? [
                        'amount' => (string) $billing->actual_cost,
                        'currency' => (string) $billing->currency,
                        'delivery_status' => $billing->delivery_status,
                        'document_number' => $billing->billing_document_number,
                    ] : null,
                    'handling_cost' => $handling?->actual_cost !== null ? [
                        'amount' => (string) $handling->actual_cost,
                        'currency' => (string) $handling->currency,
                        'document_number' => $handling->billing_document_number,
                    ] : null,
                    'source' => 'erp',
                ];
            })
            ->all();

        return [
            'mode' => 'read_only_projection',
            'warehouse_id' => (int) $warehouse->id,
            'warehouse_code' => (string) $warehouse->code,
            'logistics_key' => is_string($logisticsKey) ? $logisticsKey : null,
            'logistics_url' => is_string($logisticsKey) ? "/logistics/oversea/{$logisticsKey}?view=all" : '/logistics/oversea',
            'counts' => $counts,
            'rows' => $rows,
            'legacy' => [
                'enabled' => false,
                'status' => 'not_configured',
                'count' => 0,
            ],
        ];
    }
}
