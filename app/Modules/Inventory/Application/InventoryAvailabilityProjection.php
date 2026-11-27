<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use Illuminate\Support\Collection;

/** Inventory UI uses exactly the same availability decision as allocation. */
final class InventoryAvailabilityProjection
{
    public function __construct(private readonly InventoryAvailabilityReader $reader) {}

    /** @return array<int,int> Inventory row ID => allocation quantity */
    public function forRows(Collection $rows): array
    {
        $result = [];
        foreach ($rows->groupBy('product_id')->chunk(200) as $group) {
            $availability = $this->reader->forProductIds($group->keys()->all());
            foreach ($group as $productId => $inventoryRows) {
                $byWarehouse = collect($availability[$productId] ?? [])->keyBy('warehouse_id');
                foreach ($inventoryRows as $row) {
                    // Inactive warehouses are not eligible for allocation.
                    $result[(int) $row->id] = (int) ($byWarehouse->get($row->warehouse_id)['quantity_available'] ?? 0);
                }
            }
        }
        return $result;
    }
}
