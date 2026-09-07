<?php

namespace App\Modules\Logistics\Infrastructure;

use App\Modules\Logistics\Application\Contracts\CarrierBillingEvidenceReader;
use Illuminate\Database\Eloquent\Model;

final class EloquentCarrierBillingEvidenceReader implements CarrierBillingEvidenceReader
{
    public function read(int|string $orderId): ?array
    {
        $shipmentClass = 'App\\Modules\\Logistics\\Infrastructure\\Models\\Shipment';
        $shipments = $shipmentClass::query()->with('billingEvidence')->where('order_id', $orderId)->orderBy('id')->get();
        if ($shipments->count() !== 1) {
            return [
                'blocked' => $shipments->isEmpty() ? 'canonical_shipment_missing' : 'multiple_canonical_shipments',
                'shipment_count' => $shipments->count(),
            ];
        }

        $shipment = $shipments->first();
        $billing = $shipment->billingEvidence;
        if ($billing === null) {
            return ['blocked' => 'carrier_billing_missing', 'shipment_id' => (int) $shipment->getKey()];
        }

        return [
            'shipment_id' => (int) $shipment->getKey(),
            'billing_id' => (int) $billing->getKey(),
            'actual_cost' => $billing->actual_cost,
            'currency' => $billing->currency,
            'billing_document_number' => $billing->billing_document_number,
            'provider_reference' => $billing->provider_reference,
            'data_source' => $billing->data_source,
            'billed_at' => $billing->billed_at,
            'updated_at' => $billing->updated_at,
        ];
    }
}
