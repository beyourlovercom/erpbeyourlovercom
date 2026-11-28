<?php

namespace App\Modules\Logistics\Application\OverseaFiles;

use App\Modules\Logistics\Infrastructure\Models\Shipment;

final class OverseaOutboundFileService
{
    public function __construct(
        private readonly Wms64OutboundExporter $wms,
        private readonly MorePlusOutboundExporter $morePlus,
        private readonly CanadaOutboundExporter $canada,
    ) {}

    /**
     * One file per staff click; a new provider reference avoids historical
     * reference collisions without creating any remote OMS order or ERP write.
     * Operator must check the current OMS draft inbox before manual import.
     *
     * @return array{filename:string,bytes:string,reference:string}
     */
    public function exportManualUsSmOrder(Shipment $shipment): array
    {
        $order = $shipment->order;
        abort_unless($order, 422, '找不到订单。');
        abort_if($order->originalFulfillmentChildren()->exists(), 409, '拆单母订单禁止直接导出。');
        abort_if($order->fulfillment_parent_id !== null
            && ($order->order_status?->value !== 'pending_shipment'
                || $shipment->shipment_status !== 'pending' || $shipment->shipped_at !== null
                || filled($shipment->tracking_number)), 409, '子订单已有实际物流，请使用专用补发流程。');
        $base = trim((string) $this->references()->warehouse($order));
        abort_if($base === '', 422, '订单缺少仓库参考单号。');
        $reference = $this->wms->uniqueUsSmReference($base);
        $suffix = substr($reference, -13);

        return [
            'filename' => sprintf('007-us-3sm-%d-%s.xlsx', $order->id, $suffix),
            'bytes' => $this->wms->exportManualUsSmOrder($shipment, $reference),
            'reference' => $reference,
        ];
    }

    private function references(): WarehouseOrderReference
    {
        return app(WarehouseOrderReference::class);
    }

    /** Export only an explicitly approved independent reissue, with a fresh
     * provider reference; do not duplicate the original commercial order. */
    public function exportReshipDraft(Shipment $shipment, string $thirdOrderNo): array
    {
        return [
            'filename' => sprintf('007-us-3sm-reship-%s.xlsx', substr($thirdOrderNo,-12)),
            'bytes' => $this->wms->exportReshipDraft($shipment,$thirdOrderNo),
        ];
    }

    /** @param iterable<Shipment> $shipments @return array{filename:string,bytes:string} */
    public function export(string $warehouseKey, iterable $shipments): array
    {
        $shipments = collect($shipments);
        foreach ($shipments as $shipment) {
            abort_if($shipment->order?->originalFulfillmentChildren()->exists()
                || $shipment->order?->order_status?->value !== 'pending_shipment'
                || $shipment->shipment_status !== 'pending' || $shipment->shipped_at !== null,
                409, '拆单母订单或已发货订单禁止导出。');
        }
        $bytes = match ($warehouseKey) {
            'us-ny', 'us-3sm', 'de-tianyun' => $this->wms->export($warehouseKey, $shipments),
            'us-moreplus' => $this->morePlus->export($shipments),
            'ca' => $this->canada->export($shipments),
            default => abort(404, '未知海外仓。'),
        };

        return [
            'filename' => sprintf('007-%s-orders-%s.xlsx', $warehouseKey, now()->format('ymd')),
            'bytes' => $bytes,
        ];
    }
}
