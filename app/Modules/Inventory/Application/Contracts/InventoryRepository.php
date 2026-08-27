<?php

namespace App\Modules\Inventory\Application\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

interface InventoryRepository
{
    public function dashboard(): array;
    public function warehouseList(): array;
    public function findWarehouseBySlug(string $slug): ?Model;
    public function createWarehouse(array $attributes): Model;
    public function updateWarehouse(Model $warehouse, array $attributes): Model;
    public function warehouseDetail(Model $warehouse): Model;
    public function productInventory(array $filters = [], int $perPage = 100): LengthAwarePaginator;
    public function upsertInventory(Model $warehouse, Model $product, array $attributes): Model;
    public function findInventory(Model $warehouse, int $inventoryId): Model;
    public function updateInventory(Model $inventory, array $attributes): Model;
    public function syncServiceRegions(Model $warehouse, array $countryCodes): void;
}
