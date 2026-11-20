<?php

namespace App\Modules\Logistics\Application\Domestic;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Logistics\Application\WarehouseFinalSkuResolver;
use Illuminate\Validation\ValidationException;

/**
 * Optional customs/packing data for factory-origin EU small packets.
 * Does not alter provider-frozen Speeda/Weship XLSX columns.
 */
final class ChinaSmallPacketBarcode69Reader
{
    public function __construct(private readonly WarehouseFinalSkuResolver $finalSkus) {}

    /** @return list<array{sku:string,quantity:int,barcode_69:string}> */
    public function forOrder(Order $order): array
    {
        $order->loadMissing(['items.product', 'shipment']);
        $rows = [];
        foreach ($order->items as $item) {
            $sku = trim((string) ($item->final_sku ?: $item->resolved_fulfillment_sku ?: $item->sku));
            $product = $item->product;
            // Legacy order lines sometimes have no product_id. Resolve only a unique,
            // exact SKU; never guess from a partial SKU or override a mismatched link.
            if ($product === null && $item->product_id === null && $sku !== '') {
                $matches = Product::query()->where('sku', $sku)->limit(2)->get();
                $product = $matches->count() === 1 ? $matches->first() : null;
            }
            // A verified warehouse alias is the same physical product; its
            // manufacturer barcode remains tied to the linked product record.
            $verifiedPhysicalSku = $product !== null
                && $item->product_id !== null
                && filled($item->final_sku)
                && (int) $order->shipment?->warehouse_id > 0
                && strcasecmp((string) ($this->finalSkus->mapped((int) $product->id, (int) $order->shipment->warehouse_id) ?? ''), $sku) === 0;
            if (! $product || ! $product->barcode_69
                || (trim((string) $product->sku) !== $sku && ! $verifiedPhysicalSku)) {
                throw ValidationException::withMessages([
                    'barcode_69' => "订单 {$order->erp_order_id} 商品 {$sku} 缺少经过SKU核实的69条形码。",
                ]);
            }
            $rows[] = [
                'sku' => $sku,
                'quantity' => (int) $item->quantity,
                'barcode_69' => (string) $product->barcode_69,
            ];
        }
        return $rows;
    }
}
