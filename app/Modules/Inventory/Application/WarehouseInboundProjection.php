<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInboundBatch;

final class WarehouseInboundProjection
{
    public function forWarehouse(Warehouse $warehouse): array
    {
        $batches = WarehouseInboundBatch::query()
            ->where('warehouse_id', $warehouse->id)
            ->with(['items.product:id,sku,name'])
            ->orderByRaw("CASE status WHEN 'in_transit' THEN 0 WHEN 'received' THEN 1 ELSE 2 END")
            ->orderBy('eta_date')
            ->orderByDesc('id')
            ->get();

        return [
            'summary' => [
                'batch_count' => $batches->count(),
                'in_transit_batches' => $batches->where('status', 'in_transit')->count(),
                'in_transit_units' => $batches->where('status', 'in_transit')->sum('quantity_total'),
                'in_transit_cartons' => $batches->where('status', 'in_transit')->sum(fn ($b) => (int) ($b->carton_count ?? 0)),
            ],
            'batches' => $batches->map(fn (WarehouseInboundBatch $batch): array => [
                'id' => $batch->id,
                'reference' => $batch->reference,
                'transport_mode' => $batch->transport_mode,
                'status' => $batch->status,
                'carton_count' => $batch->carton_count,
                'quantity_total' => $batch->quantity_total,
                'gross_weight_kg' => $batch->gross_weight_kg,
                'volume_cbm' => $batch->volume_cbm,
                'shipped_on' => $batch->shipped_on?->format('Y-m-d'),
                'eta_date' => $batch->eta_date?->format('Y-m-d'),
                'eta_is_estimated' => (bool) $batch->eta_is_estimated,
                'received_on' => $batch->received_on?->format('Y-m-d'),
                'source_filename' => $batch->source_filename,
                'note' => $batch->note,
                'items' => $batch->items->map(fn ($item): array => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'source_sku' => $item->source_sku,
                    'canonical_sku' => $item->canonical_sku,
                    'source_product_name' => $item->source_product_name,
                    'quantity' => $item->quantity,
                    'evidence_type' => $item->evidence_type,
                    'match_status' => $item->match_status,
                    'product_name' => $item->product?->name ?? $item->source_product_name,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
