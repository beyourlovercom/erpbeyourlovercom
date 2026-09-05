<?php

namespace App\Modules\Inventory\Infrastructure;

use App\Modules\Inventory\Application\Contracts\InventoryRepository;
use App\Modules\Inventory\Application\InventoryStockStatus;
use App\Modules\Inventory\Infrastructure\Models\InventoryAdjustment;
use App\Modules\Inventory\Infrastructure\Models\InventorySyncBatch;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

final class EloquentInventoryRepository implements InventoryRepository
{
    public function dashboard(): array
    {
        $rows = WarehouseInventory::query()->get();
        $warehouses = $this->warehouseList();

        $statusCounts = $rows->countBy(fn ($row) => InventoryStockStatus::classify((int) $row->quantity_available));

        return [
            'warehouse_count' => collect($warehouses)->where('is_active', true)->count(),
            'warehouse_data_gap_count' => collect($warehouses)->where('inventory_state', 'never_imported')->count(),
            'warehouse_confirmed_count' => collect($warehouses)->where('inventory_state', 'confirmed')->count(),
            'sku_rows' => $rows->count(),
            'quantity_on_hand' => $rows->sum('quantity_on_hand'),
            'quantity_reserved' => $rows->sum('quantity_reserved'),
            'quantity_available' => $rows->sum(fn ($row) => $row->quantity_available),
            'out_of_stock_rows' => (int) $statusCounts->get(InventoryStockStatus::OUT_OF_STOCK, 0),
            'low_stock_rows' => (int) $statusCounts->get(InventoryStockStatus::LOW_STOCK, 0),
            'in_stock_rows' => (int) $statusCounts->get(InventoryStockStatus::IN_STOCK, 0),
            'stock_alert_rows' => (int) $statusCounts->get(InventoryStockStatus::OUT_OF_STOCK, 0) + (int) $statusCounts->get(InventoryStockStatus::LOW_STOCK, 0),
            'warehouses' => $warehouses,
        ];
    }

    public function warehouseList(): array
    {
        $confirmedBatches = InventorySyncBatch::query()
            ->where('status', 'confirmed')
            ->orderByDesc('confirmed_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('warehouse_id');

        return Warehouse::query()
            ->withCount('inventories')
            ->withSum('inventories as quantity_on_hand_total', 'quantity_on_hand')
            ->withMax('inventories as inventory_updated_at', 'updated_at')
            ->orderBy('priority')
            ->orderBy('name')
            ->get()
            ->map(function ($w) use ($confirmedBatches): array {
                $batches = $confirmedBatches->get($w->id, collect());
                $latest = $batches->first();
                $hasFullBaseline = $batches->contains(fn ($batch): bool => $batch->sync_mode === 'full');
                $inventoryRows = (int) $w->inventories_count;

                $state = match (true) {
                    $hasFullBaseline => 'confirmed',
                    $latest !== null => 'partial_only',
                    $inventoryRows > 0 => 'manual',
                    default => 'never_imported',
                };

                $lastInventoryAt = $latest?->stock_count_at ?? $latest?->confirmed_at;
                if ($lastInventoryAt === null && $w->inventory_updated_at !== null) {
                    $lastInventoryAt = \Illuminate\Support\Carbon::parse($w->inventory_updated_at);
                }

                $known = $state !== 'never_imported';

                return [
                    'id'=>$w->id,'slug'=>$w->slug,'code'=>$w->code,'name'=>$w->name,'country_code'=>$w->country_code,
                    'address'=>$this->address($w),'ownership_type'=>$w->ownership_type,'is_active'=>$w->is_active,'priority'=>$w->priority,
                    'product_count'=>$known ? $inventoryRows : null,
                    'quantity_on_hand'=>$known ? (int)($w->quantity_on_hand_total ?? 0) : null,
                    'inventory_state'=>$state,
                    'inventory_state_label'=>match ($state) {
                        'confirmed' => '已确认',
                        'partial_only' => '仅部分数据',
                        'manual' => '手工数据',
                        default => '库存未导入',
                    },
                    'has_full_snapshot'=>$hasFullBaseline,
                    'can_auto_allocate'=>$state === 'confirmed',
                    'latest_sync_mode'=>$latest?->sync_mode,
                    'latest_batch_id'=>$latest?->id,
                    'last_inventory_at'=>$lastInventoryAt?->toIso8601String(),
                ];
            })->all();
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
