<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Support\Facades\DB;

final class InventoryMatrixService
{
    public const WAREHOUSE_CODES = ['FACTORY', 'US-LA', 'US-NY', 'US-SM', 'DE', 'CA'];

    /** Read-only projection. A missing inventory record is unknown, not zero. */
    public function rows(): array
    {
        $warehouses = Warehouse::query()->whereIn(DB::raw('UPPER(code)'), self::WAREHOUSE_CODES)
            ->pluck('code', 'id')->map(fn (string $code): string => strtoupper($code));
        $ids = $warehouses->keys()->all();

        // One aggregate statement keeps the inventory/manifest read consistent
        // when a local batch is activated in a concurrent transaction.
        $stock = WarehouseInventory::query()->whereIn('warehouse_id', $ids)
            ->selectRaw('warehouse_id, product_id, quantity_on_hand AS on_hand, 0 AS transit, 0 AS pending')
            ->toBase();
        $sea = DB::table('warehouse_inbound_items as i')
            ->join('warehouse_inbound_batches as b', 'b.id', '=', 'i.batch_id')
            ->whereIn('b.warehouse_id', $ids)
            ->where('b.transport_mode', 'sea')
            ->whereIn('b.status', ['in_transit', 'port_arrived', 'received'])
            ->where('i.match_status', 'matched')->whereNotNull('i.product_id')
            ->selectRaw("b.warehouse_id, i.product_id, NULL AS on_hand,
                CASE WHEN b.status IN ('in_transit','port_arrived') THEN i.quantity ELSE 0 END AS transit,
                CASE WHEN b.status = 'received' THEN i.quantity ELSE 0 END AS pending");
        $totals = DB::query()->fromSub($stock->unionAll($sea), 'matrix_stock')
            ->selectRaw('warehouse_id, product_id, SUM(on_hand) AS on_hand, SUM(transit) AS transit, SUM(pending) AS pending')
            ->groupBy('warehouse_id', 'product_id')->get()->groupBy('product_id');

        $blank = array_fill_keys(self::WAREHOUSE_CODES, ['on_hand'=>null, 'transit'=>0, 'pending'=>0]);

        return Product::query()->businessCatalog()->whereNotNull('sku')->where('sku', '<>', '')
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $totals->keys()->all()))
            ->orderBy('sku')->orderBy('id')->get(['id','sku','name','material_full_name'])
            ->map(function (Product $product) use ($totals, $warehouses, $blank): array {
                $cells = $blank;
                foreach ($totals->get($product->id, []) as $record) {
                    $code = $warehouses->get($record->warehouse_id);
                    if (!isset($cells[$code])) continue;
                    $cells[$code] = [
                        'on_hand' => $record->on_hand === null ? null : (int) $record->on_hand,
                        'transit' => (int) $record->transit,
                        'pending' => (int) $record->pending,
                    ];
                }

                return [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'material_name' => $product->material_full_name ?: $product->name,
                    'warehouses' => $cells,
                ];
            })->all();
    }
}
