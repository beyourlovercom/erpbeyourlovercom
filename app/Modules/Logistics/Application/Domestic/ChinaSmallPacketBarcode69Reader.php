<?php

namespace App\Modules\Logistics\Application\Domestic;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;
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
            // Legacy order lines sometimes have no product_id. Resolve only a unique,
            // exact SKU; never guess from a partial SKU or override a mismatched link.
            if ($product === null && $item->product_id === null && $sku !== '') {
                $matches = Product::query()->where('sku', $sku)->limit(2)->get();
                $product = $matches->count() === 1 ? $matches->first() : null;
            }
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
