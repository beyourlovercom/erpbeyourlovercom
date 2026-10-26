<?php

namespace App\Modules\Inventory\Infrastructure;

use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use App\Modules\Inventory\Infrastructure\Models\InventorySyncBatch;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInventoryLot;
use App\Modules\Logistics\Application\Xlwms\XlwmsInventoryCoverage;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsInventoryUnverifiedAge;
use Illuminate\Support\Facades\Schema;

final class EloquentInventoryAvailabilityReader implements InventoryAvailabilityReader
{
    /** @var array<int,array<string,mixed>>|null */
    private ?array $activeWarehousesCache = null;

    public function __construct(private readonly ?XlwmsInventoryCoverage $xlwmsCoverage = null) {}

    public function activeWarehouses(): array
    {
        if ($this->activeWarehousesCache !== null) {
            return $this->activeWarehousesCache;
        }

        $confirmedBatches = InventorySyncBatch::query()
            ->where('status', 'confirmed')
            ->orderByDesc('confirmed_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('warehouse_id');

        return $this->activeWarehousesCache = Warehouse::query()
            ->with(['serviceRegions' => fn ($query) => $query->orderBy('priority')->orderBy('country_code')])
            ->withCount('inventories')
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('name')
            ->get(['id', 'slug', 'name', 'code', 'country_code', 'priority', 'fulfillment_available_on'])
            ->map(function (Warehouse $warehouse) use ($confirmedBatches): array {
                $batches = $confirmedBatches->get($warehouse->id, collect());
                $latest = $batches->first();
                $hasFullBaseline = $batches->contains(fn ($batch): bool => $batch->sync_mode === 'full');
                $state = match (true) {
                    $hasFullBaseline => 'confirmed',
                    $latest !== null => 'partial_only',
                    (int) $warehouse->inventories_count > 0 => 'manual',
                    default => 'never_imported',
                };

                return [
                    'id' => $warehouse->id, 'slug' => $warehouse->slug, 'name' => $warehouse->name,
                    'code' => $warehouse->code, 'country_code' => $warehouse->country_code,
                    'priority' => (int) $warehouse->priority, 'inventory_state' => $state,
                    'fulfillment_available_on' => $warehouse->fulfillment_available_on?->toDateString(),
                    'can_auto_allocate' => $state === 'confirmed' && ($warehouse->fulfillment_available_on === null || $warehouse->fulfillment_available_on->isToday() || $warehouse->fulfillment_available_on->isPast()),
                    'service_regions' => $warehouse->serviceRegions->map(fn ($region): array => [
                        'country_code' => strtoupper((string) $region->country_code), 'priority' => (int) $region->priority,
                    ])->values()->all(),
                ];
            })->all();
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
            ->get(['product_id', 'warehouse_id', 'quantity_on_hand', 'quantity_reserved', 'expected_restock_qty'])
            ->keyBy(fn (WarehouseInventory $row): string => $row->product_id.':'.$row->warehouse_id);
        $usSm = $warehouses->firstWhere('code', 'US-SM');
        $providerAvailable = $usSm !== null
            ? app(XlwmsInventoryCoverage::class)->providerAvailableByProductId((int) $usSm['id']) : null;
        $unknown = collect();
        $dated = collect();
        if ($usSm !== null && Schema::hasTable('xlwms_inventory_unverified_ages')) {
            $unknown = XlwmsInventoryUnverifiedAge::query()->where('warehouse_id', $usSm['id'])->whereIn('product_id', $ids)->pluck('unknown_age_quantity', 'product_id');
            $dated = WarehouseInventoryLot::query()->where('warehouse_id', $usSm['id'])->whereIn('product_id', $ids)->where('stock_status', 'sellable')->whereNotNull('putaway_date')->where('quantity', '>=', 0)->selectRaw('product_id, SUM(quantity) AS quantity')->groupBy('product_id')->pluck('quantity', 'product_id');
        }

        $result = [];
        foreach ($ids as $productId) {
            $result[$productId] = $warehouses->map(function (array $warehouse) use ($rows, $productId, $usSm, $unknown, $dated, $providerAvailable): array {
                /** @var WarehouseInventory|null $row */
                $row = $rows->get($productId.':'.$warehouse['id']);
                $state = (string) $warehouse['inventory_state'];
                $knownForProduct = $state === 'confirmed' || $row !== null;
                $onHand = $knownForProduct ? (int) ($row?->quantity_on_hand ?? 0) : null;
                $reserved = $knownForProduct ? (int) ($row?->quantity_reserved ?? 0) : null;
                $available = $knownForProduct ? max($onHand - $reserved, 0) : null;
                if ($available !== null && $usSm !== null && (int) $warehouse['id'] === (int) $usSm['id']
                    && $providerAvailable !== null) {
                    // Count OMS locked physical stock in QOH, NOT in automatic
                    // fulfillment availability. Conservatively subtract ERP
                    // reservations too: they may cover unrelated reservations.
                    $available = min($available, max(0,
                        (int) ($providerAvailable[(int) $productId] ?? 0) - $reserved));
                }
                if ($available !== null && $usSm !== null && (int) $warehouse['id'] === (int) $usSm['id'] && $unknown->has($productId)) {
                    $available = min($available, max((int) $dated->get($productId, 0) - $reserved, 0));
                    $unverified = (int) $unknown->get($productId, 0);
                    if ($unverified > 0) $available = min($available, max($onHand - $unverified - $reserved, 0));
                }

                return [
                    'warehouse_id' => $warehouse['id'],
                    'warehouse_slug' => $warehouse['slug'],
                    'warehouse_name' => $warehouse['name'],
                    'inventory_state' => $state,
                    'can_auto_allocate' => (bool) $warehouse['can_auto_allocate'],
                    'quantity_on_hand' => $onHand,
                    'quantity_reserved' => $reserved,
                    'quantity_available' => $available,
                    // A missing product row does not prove that expected restock is zero.
                    'expected_restock_qty' => $row !== null && $row->expected_restock_qty !== null ? (int) $row->expected_restock_qty : null,
                ];
            })->all();
        }

        return $result;
    }
}
