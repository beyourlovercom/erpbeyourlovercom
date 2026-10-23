<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Inventory\Application\InventoryService;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use Inertia\Inertia;
use Inertia\Response;

/** The existing Warehouse Master is the only source of warehouse settings. */
final class WarehouseSettingsController
{
    public function index(InventoryService $inventory): Response
    {
        $warehouses = Warehouse::query()->orderBy('name')->get(['id', 'slug', 'code', 'name', 'country_code', 'is_active']);
        return Inertia::render('Settings/Warehouses/Index', [
            'warehouses' => $warehouses->map(fn (Warehouse $w): array => [
                'id' => (int) $w->id,
                'code' => (string) $w->code,
                'name' => (string) $w->name,
                'country_code' => $w->country_code,
                'is_active' => (bool) $w->is_active,
                'url' => '/inventory/'.rawurlencode($inventory->warehousePublicSlug($w)).'?tab=config',
            ])->values()->all(),
            'autoDispatchEnabled' => false,
        ]);
    }
}
