<?php

namespace App\Modules\Inventory\Infrastructure;

use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;

final class EloquentInventoryAvailabilityReader implements InventoryAvailabilityReader
{
    public function activeWarehouses(): array
    {
        return Warehouse::query()
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('name')
            ->get(['id', 'slug', 'name', 'code', 'country_code'])
            ->map(fn (Warehouse $warehouse): array => [
                'id' => $warehouse->id,
                'slug' => $warehouse->slug,
                'name' => $warehouse->name,
                'code' => $warehouse->code,
                'country_code' => $warehouse->country_code,
            ])->all();
    }

    public function forProductIds(array $productIds): array
    {
        $ids = collect($productIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $warehouses = collect($this->activeWarehouses());
        $rows = WarehouseInventory::query()
            ->whereIn('product_id', $ids->all())
            ->whereIn('warehouse_id', $warehouses->pluck('id')->all())
            ->get(['product_id', 'warehouse_id', 'quantity_on_hand', 'quantity_reserved'])
            ->keyBy(fn (WarehouseInventory $row): string => $row->product_id.':'.$row->warehouse_id);

        $result = [];
        foreach ($ids as $productId) {
            $result[$productId] = $warehouses->map(function (array $warehouse) use ($rows, $productId): array {
                /** @var WarehouseInventory|null $row */
                $row = $rows->get($productId.':'.$warehouse['id']);
                $onHand = $row?->quantity_on_hand ?? 0;
                $reserved = $row?->quantity_reserved ?? 0;

                return [
                    'warehouse_id' => $warehouse['id'],
                    'warehouse_slug' => $warehouse['slug'],
                    'warehouse_name' => $warehouse['name'],
                    'quantity_on_hand' => $onHand,
                    'quantity_reserved' => $reserved,
                    'quantity_available' => max($onHand - $reserved, 0),
                ];
            })->all();
        }

        return $result;
    }
}
