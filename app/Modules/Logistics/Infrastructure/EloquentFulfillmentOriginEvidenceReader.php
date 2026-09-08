<?php

namespace App\Modules\Logistics\Infrastructure;

use App\Modules\Logistics\Application\Contracts\FulfillmentOriginEvidenceReader;

final class EloquentFulfillmentOriginEvidenceReader implements FulfillmentOriginEvidenceReader
{
    public function read(int|string $orderId): ?array
    {
        $shipmentClass = 'App\\Modules\\Logistics\\Infrastructure\\Models\\Shipment';
        $shipments = $shipmentClass::query()->with('warehouse')->where('order_id', $orderId)->orderBy('id')->get();
        if ($shipments->count() !== 1) {
            return [
                'blocked' => $shipments->isEmpty() ? 'canonical_shipment_missing' : 'multiple_canonical_shipments',
                'shipment_count' => $shipments->count(),
            ];
        }

        $shipment = $shipments->first();
        $warehouse = $shipment->warehouse;
        if ($warehouse === null) {
            return ['blocked' => 'shipment_warehouse_missing', 'shipment_id' => (int) $shipment->getKey()];
        }

        return [
            'shipment_id' => (int) $shipment->getKey(),
            'warehouse_id' => (int) $warehouse->getKey(),
            'warehouse_slug' => strtolower((string) $warehouse->slug),
            'warehouse_code' => (string) $warehouse->code,
            'warehouse_name' => (string) $warehouse->name,
            'warehouse_country_code' => $warehouse->country_code === null ? null : strtoupper((string) $warehouse->country_code),
            'warehouse_selection_source' => $shipment->warehouse_selection_source,
        ];
    }
}
