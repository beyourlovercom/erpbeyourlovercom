<?php

namespace App\Modules\Logistics\Application\Domestic;

use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Validation\ValidationException;

/**
 * Optional customs/packing data for factory-origin EU small packets.
 * Does not alter provider-frozen Speeda/Weship XLSX columns.
 */
final class ChinaSmallPacketBarcode69Reader
{
    /** @return list<array{sku:string,quantity:int,barcode_69:string}> */
    public function forOrder(Order $order): array
    {
        $order->loadMissing(['items.product']);
        $rows = [];
        foreach ($order->items as $item) {
            $sku = trim((string) ($item->resolved_fulfillment_sku ?: $item->sku));
            $product = $item->product;
            if (! $product || ! $product->barcode_69 || trim((string) $product->sku) !== $sku) {
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
