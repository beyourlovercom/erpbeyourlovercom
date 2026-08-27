<?php

namespace App\Modules\Inventory\Application\Contracts;

interface InventoryAvailabilityReader
{
    /** @return array<int, array{id:int,slug:string,name:string,code:string,country_code:?string}> */
    public function activeWarehouses(): array;

    /**
     * @param array<int, int> $productIds
     * @return array<int, array<int, array{warehouse_id:int,warehouse_slug:string,warehouse_name:string,quantity_on_hand:int,quantity_reserved:int,quantity_available:int}>>
     */
    public function forProductIds(array $productIds): array;
}
