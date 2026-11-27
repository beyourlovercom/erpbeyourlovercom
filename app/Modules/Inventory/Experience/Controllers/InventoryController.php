<?php

namespace App\Modules\Inventory\Experience\Controllers;

use App\Modules\Identity\Application\AuthorizationKeys;
use App\Modules\Inventory\Application\InventoryService;
use App\Modules\Inventory\Application\InventoryMatrixService;
use App\Modules\Inventory\Application\InventoryAvailabilityProjection;
use App\Modules\Inventory\Application\WarehouseLastReadStockService;
use App\Modules\Inventory\Application\InventoryStockStatus;
use App\Modules\Inventory\Application\WarehouseOverdueInventoryService;
use App\Modules\Shell\Application\GlobalGridPilot;
use App\Modules\Products\Application\ProductLogisticsProjection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class InventoryController
{
    public function __construct(private readonly InventoryService $inventory, private readonly WarehouseOverdueInventoryService $overdue, private readonly InventoryAvailabilityProjection $availability, private readonly WarehouseLastReadStockService $lastReadStock) {}

    public function dashboard(): Response
    {
        return Inertia::render('Inventory/Dashboard', ['summary' => $this->inventory->dashboard(), 'overdue' => $this->overdue->report()['summary'], 'canManage' => auth()->user()?->can(AuthorizationKeys::InventoryWarehousesManage->value) ?? false]);
    }

    public function warehouses(): Response
    {
        return Inertia::render('Inventory/WarehouseList', ['warehouses' => $this->inventory->warehouses(), 'canManage' => auth()->user()?->can(AuthorizationKeys::InventoryWarehousesManage->value) ?? false]);
    }

    public function products(Request $request, InventoryMatrixService $matrix, ProductLogisticsProjection $logistics): Response|RedirectResponse
    {
        $view = $request->query('view');
        $canViewLogistics = (bool) $request->user()?->can('products.view');
        if (in_array($view, ['logistics','sources'], true)) abort_unless($canViewLogistics, 403);
        $pageSize = in_array((string) $request->query('per_page', '100'), ['20', '50', '100'], true)
            ? (int) $request->query('per_page', 100) : 100;
        $page = $this->inventory->productInventory($request->only(['search', 'warehouse', 'stock_status']), $pageSize);
        $warehouseFilter = trim((string) $request->input('warehouse', ''));
        $canonicalWarehouse = $warehouseFilter === '' ? '' : ($this->inventory->canonicalWarehouseSlug($warehouseFilter) ?? $warehouseFilter);
        if ($warehouseFilter !== '' && $canonicalWarehouse !== $warehouseFilter) {
            $query = array_filter([...$request->query(), 'warehouse' => $canonicalWarehouse], fn ($value): bool => $value !== null && $value !== '');

            return redirect()->route('inventory.products', $query);
        }

        $available = $this->availability->forRows($page->getCollection());
        $morePlus = $page->getCollection()->first(fn ($i) => strtoupper((string) $i->warehouse->code) === 'US-LA')?->warehouse;
        $references = $morePlus ? $this->lastReadStock->forWarehouse($morePlus) : [];
        return Inertia::render('Inventory/Products', [
            'rows' => $page->through(fn ($i) => [
                'id' => $i->id, 'warehouse' => ['slug' => $i->warehouse->slug, 'public_slug' => $this->inventory->warehousePublicSlug($i->warehouse), 'name' => $i->warehouse->name],
                'product' => ['id' => $i->product->id, 'sku' => $i->product->sku, 'name' => $i->product->name, 'material_full_name' => $i->product->material_full_name],
                'inbound_at' => $i->inbound_at?->format('Y-m-d'), 'stay_days' => $i->stay_days, 'location' => $i->location,
                'quantity_on_hand' => $i->quantity_on_hand, 'quantity_reserved' => $i->quantity_reserved, 'quantity_available' => $available[$i->id], 'provider_stock_synced_at' => $i->provider_stock_synced_at?->toIso8601String(),
                'provider_sync_status' => strtoupper((string) $i->warehouse->code) === 'US-LA'
                    ? ($references[$i->product_id]->last_read_stock_status ?? 'not_read') : null,
                'stock_status' => InventoryStockStatus::classify($available[$i->id]), 'stock_status_label' => InventoryStockStatus::label(InventoryStockStatus::classify($available[$i->id])),
                'standard_qoh' => $i->standard_qoh, 'expected_restock_qty' => $i->expected_restock_qty,
                'replenishment' => $this->inventory->replenishmentMetrics($i, $available[$i->id]),
            ])->toArray(),
            'warehouses' => $this->inventory->warehouses(), 'filters' => ['search' => $request->input('search'), 'warehouse' => $canonicalWarehouse, 'stock_status' => $request->input('stock_status')],
            'matrixRows' => fn () => $matrix->rows(),
            'canViewLogistics' => $canViewLogistics,
            'logisticsRows' => $view === 'logistics' ? fn () => $logistics->rows(array_column($matrix->rows(), 'id')) : null,
            'sourceRows' => $view === 'sources' ? fn () => $logistics->sources(array_column($matrix->rows(), 'id')) : null,
            'inventoryView' => in_array($request->query('view'), ['product','warehouse','logistics','sources'], true) ? $request->query('view') : ($request->has('warehouse') || $request->has('global_grid') ? 'warehouse' : null),
            // This approved page is a separate grid rollout; do not change other pilots.
            'warehouseGridEnabled' => ! (GlobalGridPilot::available() && $request->query('global_grid') === '0'),
            'globalGridPilotAvailable' => GlobalGridPilot::available(),
            'globalGridPilotEnabled' => GlobalGridPilot::enabled($request),
        ]);
    }

    public function exportProductLogistics(Request $request, InventoryMatrixService $matrix, ProductLogisticsProjection $logistics): StreamedResponse
    {
        abort_unless($request->user()?->can('products.view'), 403);
        $view = $request->query('view', 'logistics');
        abort_unless(in_array($view, ['logistics','sources'], true), 422);
        $ids = array_column($matrix->rows(), 'id');
        $rows = $view === 'sources' ? $logistics->sources($ids) : $logistics->rows($ids);
        $columns = $view === 'sources' ? [
            'sku'=>'SKU','material_name'=>'物料名称','source'=>'数据来源','kind'=>'测量类型','weight_kg'=>'重量 kg',
            'dimensions'=>'尺寸 cm','method'=>'包装方案','status'=>'审核状态','current'=>'当前采用','observed_at'=>'测量时间',
            'reviewed_at'=>'审核时间','media_count'=>'证据数量','reference'=>'来源编号',
        ] : [
            'sku'=>'SKU','material_name'=>'物料名称','retail_dimensions'=>'彩盒尺寸 cm','retail_weight_kg'=>'彩盒毛重 kg',
            'retail_volume_cm3'=>'彩盒体积 cm³','retail_weight_source'=>'彩盒重量来源','unclassified_package_dimensions'=>'未分类包装参考 cm',
            'shipping_dimensions'=>'物流尺寸 cm','shipping_volume_cm3'=>'物流体积 cm³','shipping_weight_kg'=>'物流实重 kg',
            'packaging_method'=>'包装方案','shipping_status'=>'物流参数状态','volume_weight_kg'=>'体积重 kg',
            'billable_weight_kg'=>'计费重 kg','billing_basis'=>'计费状态','reference_weight_kg'=>'运输参考重量 kg',
            'carton_dimensions'=>'整箱尺寸 cm','carton_weight_kg'=>'整箱毛重 kg','carton_cbm'=>'整箱 CBM',
            'units_per_carton'=>'装箱数量','estimated_carton_dimensions'=>'外箱预估尺寸 cm','estimated_carton_cbm'=>'外箱预估 CBM',
        ];
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray(array_values($columns), null, 'A1');
        foreach ($rows as $i => $row) {
            foreach (array_keys($columns) as $j => $field) {
                $v = $row[$field] ?? null;
                if ($v === null) continue;
                $sheet->setCellValueExplicit([$j+1,$i+2], is_bool($v) ? ($v ? '是' : '否') : $v, is_int($v) || is_float($v) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            }
        }
        $sheet->freezePane('C2');
        return response()->streamDownload(function () use ($book): void {
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, 'ERP-product-'.$view.'-'.now()->format('Ymd').'.xlsx', ['Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function productSearch(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('inventory.view') || $request->user()?->can('inventory.packaging.submit'), 403);
        $q = trim((string) $request->input('q', ''));

        return response()->json($q === '' ? [] : $this->inventory->searchProducts($q));
    }
}
