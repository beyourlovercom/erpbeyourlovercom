<?php

namespace App\Modules\Inventory\Infrastructure;

use App\Modules\Inventory\Application\Contracts\InventoryRepository;
use App\Modules\Inventory\Infrastructure\Models\InventoryAdjustment;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

final class EloquentInventoryRepository implements InventoryRepository
{
    public function dashboard(): array
    {
        $rows = WarehouseInventory::query()->get();
        return [
            'warehouse_count' => Warehouse::query()->where('is_active', true)->count(),
            'sku_rows' => $rows->count(),
            'quantity_on_hand' => $rows->sum('quantity_on_hand'),
            'quantity_reserved' => $rows->sum('quantity_reserved'),
            'quantity_available' => $rows->sum(fn ($row) => $row->quantity_available),
            'out_of_stock_rows' => $rows->filter(fn ($row) => $row->quantity_available === 0)->count(),
            'warehouses' => $this->warehouseList(),
        ];
    }

    public function warehouseList(): array
    {
        return Warehouse::query()->withCount('inventories')->withSum('inventories as quantity_on_hand_total','quantity_on_hand')->orderBy('priority')->orderBy('name')->get()->map(fn ($w) => [
            'id'=>$w->id,'slug'=>$w->slug,'code'=>$w->code,'name'=>$w->name,'country_code'=>$w->country_code,
            'address'=>$this->address($w),'is_active'=>$w->is_active,'priority'=>$w->priority,
            'product_count'=>$w->inventories_count,'quantity_on_hand'=>(int)($w->quantity_on_hand_total ?? 0),
        ])->all();
    }

    public function findWarehouseBySlug(string $slug): ?Model { return Warehouse::query()->where('slug',$slug)->first(); }
    public function createWarehouse(array $attributes): Model { return Warehouse::query()->create($attributes); }
    public function updateWarehouse(Model $warehouse, array $attributes): Model { $warehouse->fill($attributes)->save(); return $warehouse->fresh(); }
    public function warehouseDetail(Model $warehouse): Model { return $warehouse->load(['inventories.product','serviceRegions'])->loadCount('inventories'); }

    public function productInventory(array $filters = [], int $perPage = 100): LengthAwarePaginator
    {
        return WarehouseInventory::query()->with(['warehouse','product'])
            ->when($filters['warehouse'] ?? null, fn ($q,$slug) => $q->whereHas('warehouse', fn ($w) => $w->where('slug',$slug)))
            ->when($filters['search'] ?? null, fn ($q,$search) => $q->whereHas('product', fn ($p) => $p->where('sku','like',"%{$search}%")->orWhere('name','like',"%{$search}%")))
            ->orderByDesc('inbound_at')->orderBy('id')->paginate($perPage)->withQueryString();
    }

    public function upsertInventory(Model $warehouse, Model $product, array $attributes): Model
    {
        $item = WarehouseInventory::query()->firstOrNew(['warehouse_id'=>$warehouse->getKey(),'product_id'=>$product->getKey()]);
        $before = $item->exists ? $item->only(['quantity_on_hand','quantity_reserved']) : ['quantity_on_hand'=>0,'quantity_reserved'=>0];
        $item->fill($attributes); $item->save();
        InventoryAdjustment::query()->create([
            'warehouse_id'=>$warehouse->getKey(),'product_id'=>$product->getKey(),'warehouse_inventory_id'=>$item->id,
            'quantity_before'=>$before['quantity_on_hand'],'quantity_after'=>$item->quantity_on_hand,
            'reserved_before'=>$before['quantity_reserved'],'reserved_after'=>$item->quantity_reserved,
            'actor_user_id'=>auth()->id(),'reason'=>'inventory_saved',
        ]);
        return $item->fresh(['warehouse','product']);
    }

    public function findInventory(Model $warehouse, int $inventoryId): Model { return WarehouseInventory::query()->where('warehouse_id',$warehouse->getKey())->findOrFail($inventoryId); }
    public function updateInventory(Model $inventory, array $attributes): Model
    {
        $before=$inventory->only(['quantity_on_hand','quantity_reserved']); $inventory->fill($attributes); $inventory->save();
        InventoryAdjustment::query()->create([
            'warehouse_id'=>$inventory->warehouse_id,'product_id'=>$inventory->product_id,'warehouse_inventory_id'=>$inventory->id,
            'quantity_before'=>$before['quantity_on_hand'],'quantity_after'=>$inventory->quantity_on_hand,
            'reserved_before'=>$before['quantity_reserved'],'reserved_after'=>$inventory->quantity_reserved,
            'actor_user_id'=>auth()->id(),'reason'=>'manual_inventory_adjustment',
        ]);
        return $inventory->fresh(['warehouse','product']);
    }

    public function syncServiceRegions(Model $warehouse, array $countryCodes): void
    {
        $warehouse->serviceRegions()->delete();
        foreach ($countryCodes as $index=>$code) $warehouse->serviceRegions()->create(['country_code'=>$code,'priority'=>$index+1]);
    }

    private function address(Model $w): string
    {
        return collect([$w->address_line1,$w->address_line2,$w->city,$w->state_region,$w->postal_code,$w->country_code])->filter()->implode(', ');
    }
}
