<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductPackagingProfile;
use Illuminate\Support\Facades\DB;

final class WarehouseRestockProjection
{
    public function forWarehouse(Warehouse $warehouse): array
    {
        $inventory = WarehouseInventory::query()
            ->where('warehouse_id', $warehouse->id)
            ->get()
            ->keyBy('product_id');

        $shipped120 = DB::table('shipments')
            ->join('order_items', 'order_items.order_id', '=', 'shipments.order_id')
            ->where('shipments.warehouse_id', $warehouse->id)
            ->whereNotNull('shipments.shipped_at')
            ->where('shipments.shipped_at', '>=', now()->subDays(120))
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) as shipped_120d_qty')
            ->pluck('shipped_120d_qty', 'order_items.product_id');

        $products = Product::query()
            ->where('is_active', true)
            ->orderBy('sku')
            ->get(['id','sku','name','weight_kg','shipping_weight_kg']);

        $unitsPerCarton = ProductPackagingProfile::query()
            ->where('is_active', true)
            ->whereNotNull('units_per_carton')
            ->whereIn('product_id', $products->pluck('id'))
            ->pluck('units_per_carton', 'product_id');

        $rows = $products->map(function (Product $product) use ($inventory, $shipped120, $unitsPerCarton): array {
            $stock = $inventory->get($product->id);
            $available = $stock ? max(0, (int) $stock->quantity_available) : 0;
            $shipped = max(0, (int) ($shipped120[$product->id] ?? 0));
            $formula = max($shipped - $available, 0);
            $manual = max(0, (int) ($stock?->expected_restock_qty ?? 0));
            $weight = $product->shipping_weight_kg ?? $product->weight_kg;
            $weightSource = $product->shipping_weight_kg !== null ? '物流重量' : ($product->weight_kg !== null ? '产品毛重' : '待补充');

            return [
                'product_id' => $product->id,
                'inventory_id' => $stock?->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'final_restock_qty' => $formula + $manual,
                'formula_restock_qty' => $formula,
                'manual_restock_qty' => $manual,
                'shipped_120d_qty' => $shipped,
                'quantity_available' => $available,
                'quantity_on_hand' => $stock ? (int) $stock->quantity_on_hand : 0,
                'quantity_reserved' => $stock ? (int) $stock->quantity_reserved : 0,
                'units_per_carton' => isset($unitsPerCarton[$product->id]) ? (int) $unitsPerCarton[$product->id] : null,
                'logistics_weight_kg' => $weight !== null ? (string) $weight : null,
                'weight_source' => $weightSource,
                'has_inventory_record' => $stock !== null,
            ];
        })->values();

        return [
            'rule' => [
                'history_days' => 120,
                'formula' => 'MAX(过去4个月真实出货件数 - 当前可用库存, 0)',
                'final_formula' => '公式应备货数 + 人工补充备货数量',
            ],
            'summary' => [
                'products' => $rows->count(),
                'formula_units' => $rows->sum('formula_restock_qty'),
                'manual_units' => $rows->sum('manual_restock_qty'),
                'final_units' => $rows->sum('final_restock_qty'),
                'products_to_restock' => $rows->where('final_restock_qty', '>', 0)->count(),
            ],
            'rows' => $rows->all(),
        ];
    }
}
