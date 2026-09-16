<?php

namespace App\Modules\Logistics\Infrastructure;

use App\Modules\Logistics\Application\Contracts\OverseasHandlingEvidenceReader;
use App\Modules\Logistics\Infrastructure\Models\Shipment;

final class EloquentOverseasHandlingEvidenceReader implements OverseasHandlingEvidenceReader
{
    public function read(int|string $orderId): ?array
    {
        $shipments=Shipment::query()->with('overseasHandlingEvidence')->where('order_id',$orderId)->orderBy('id')->get();
        if($shipments->count()!==1) return ['blocked'=>$shipments->isEmpty()?'canonical_shipment_missing':'multiple_canonical_shipments','shipment_count'=>$shipments->count()];
        $shipment=$shipments->first(); $billing=$shipment->overseasHandlingEvidence;
        if(!$billing) return ['blocked'=>'overseas_handling_billing_missing','shipment_id'=>(int)$shipment->id];
        return ['shipment_id'=>(int)$shipment->id,'billing_id'=>(int)$billing->id,'actual_cost'=>$billing->actual_cost,'currency'=>$billing->currency,
            'billing_document_number'=>$billing->billing_document_number,'provider_reference'=>$billing->provider_reference,'data_source'=>$billing->data_source,
            'billed_at'=>$billing->billed_at,'updated_at'=>$billing->updated_at];
    }
}
