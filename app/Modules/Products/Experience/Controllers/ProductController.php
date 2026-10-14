<?php

namespace App\Modules\Products\Experience\Controllers;

use App\Modules\Identity\Application\AuthorizationKeys;
use App\Modules\Audit\Application\ProductActivityProjection;
use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use App\Modules\Kol\Application\ProductKolProjection;
use App\Modules\Logistics\Application\ProductShipmentProjection;
use App\Modules\Orders\Application\ProductOrderProjection;
use App\Modules\Products\Application\Contracts\ProductExternalReferenceRepository;
use App\Modules\Products\Application\Contracts\SkuAliasRepository;
use App\Modules\Products\Application\ProductService;
use App\Modules\Products\Application\ProductWeightEvidenceService;
use App\Modules\Products\Application\WooCommerceProductInboundService;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductMedia;
use App\Modules\Products\Infrastructure\Models\ProductProviderSnapshot;
use App\Modules\Products\Infrastructure\Models\ProductParameterTask;
use App\Modules\Products\Infrastructure\Models\ProductParameterSubmission;
use App\Modules\Products\Infrastructure\Models\ProductParameterAccessory;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Inertia\Inertia;
use Inertia\Response;

final class ProductController
{
    public function __construct(
        private readonly ProductService $products,
        private readonly ProductWeightEvidenceService $weightEvidence,
        private readonly ProductExternalReferenceRepository $externalRefs,
        private readonly SkuAliasRepository $skuAliases,
        private readonly InventoryAvailabilityReader $inventory,
        private readonly ProductOrderProjection $orderProjection,
        private readonly ProductShipmentProjection $shipmentProjection,
        private readonly ProductKolProjection $kolProjection,
        private readonly ProductActivityProjection $activityProjection,
        private readonly WooCommerceProductInboundService $wooInbound,
    ) {}

    public function dashboard(): Response
    {
        return Inertia::render('Products/Dashboard', [
            'totalProducts' => $this->products->totalCount(),
        ]);
    }

    /**
     * Read-only Product OS grid. The canonical Products table remains the owner:
     * Woo snapshots/media, packaging standards and inventory are projections only.
     */
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'sort', 'direction']);
        $view = in_array($request->query('view'), ['standard','measurements','accessories'], true)
            ? $request->query('view') : 'standard';
        $parameterStatus = (string) $request->query('parameter_status', '');
        if ($view !== 'standard') $filters += ['view' => $view, 'parameter_status' => $parameterStatus];
        $perPage = $this->productListPageSize($request);
        $products = $this->products->list($filters, $perPage);
        $this->loadGridRelations($products->getCollection());
        $stock = $this->inventory->forProductIds($products->getCollection()->pluck('id')->all());
        $parameterRows = [];
        if ($view !== 'standard') {
            $ids = $products->getCollection()->pluck('id')->all();
            $tasks = ProductParameterTask::query()->whereIn('product_id', $ids)->get()->keyBy('product_id');
            $submissions = ProductParameterSubmission::query()->whereIn('product_id', $ids)
                ->whereIn('status', ['submitted','approved'])->orderByDesc('id')->get()->groupBy('product_id');
            $parts = ProductParameterAccessory::query()->whereIn('product_id', $ids)
                ->get()->groupBy('product_id');
            foreach ($ids as $id) {
                $history = $submissions->get($id, collect());
                $approved = $history->where('status', 'approved');
                $measures = [];
                foreach ($approved as $record) {
                    foreach ($record->measurements ?? [] as $key => $value) {
                        if (!array_key_exists($key, $measures) && $value !== null && $value !== '') {
                            $measures[$key] = $value;
                        }
                    }
                }
                $assigned = $tasks->get($id);
                $state = $history->isNotEmpty() ? '已填'
                    : ($assigned?->enabled ? ($assigned->is_required ? '必填' : '待填') : '其他');
                $accessories = $parts->get($id, collect());
                $parameterRows[$id] = [
                    'parameter_status' => $state,
                    'parameter_review_status' => $history->first()?->status === 'submitted' ? '待审核'
                        : ($history->first()?->status === 'approved' ? '已采纳' : null),
                    'material' => $measures['material'] ?? null,
                    'net_weight_kg' => $measures['net_weight_kg'] ?? null,
                    'color_box_gross_kg' => $measures['color_box_gross_kg'] ?? null,
                    'courier_gross_kg' => $measures['courier_gross_kg'] ?? null,
                    'package_dimensions' => $this->measurementDimension($measures, 'package'),
                    'package_volume_l' => $this->measurementVolume($measures, 'package'),
                    'carton_dimensions' => $this->measurementDimension($measures, 'carton'),
                    'carton_gross_weight_kg' => $measures['carton_gross_weight_kg'] ?? null,
                    'accessories_count' => $accessories->count(),
                    'accessories_summary' => $accessories->map(fn ($a) =>
                        $a->name.' × '.$a->quantity.' '.$a->unit)->join('、'),
                ];
            }
        }

        return Inertia::render('Products/Index', [
            'products' => $products->through(fn (Product $p): array => [
                ...$this->gridRow($p, $stock[(int) $p->id] ?? []),
                ...($parameterRows[(int) $p->id] ?? []),
            ])->toArray(),
            'filters' => [...$request->only(['search', 'per_page', 'sort', 'direction']),
                'view' => $view, 'parameter_status' => $parameterStatus],
            'parameterCounts' => $view === 'measurements' ? $this->parameterCoverageCounts() : [],
            'canOperate' => Auth::user()?->can(AuthorizationKeys::ProductsOperate->value) ?? false,
        ]);
    }

    /**
     * Export every row matching the current search, not merely the current page.
     * All cell values are explicit strings so untrusted product names cannot
     * become spreadsheet formulas when an XLSX file is opened.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $request->only(['search', 'sort', 'direction']);
        $products = $this->products->list($filters, max(1, $this->products->totalCount()));
        $this->loadGridRelations($products->getCollection());
        $stock = $this->inventory->forProductIds($products->getCollection()->pluck('id')->all());
        $fields = self::gridExportColumns();
        $requested = array_filter(explode(',', (string) $request->query('columns', '')));
        $columns = [];
        foreach ($requested as $key) {
            if (isset($fields[$key]) && ! isset($columns[$key])) $columns[$key] = $fields[$key];
        }
        if (! $columns) $columns = $fields;

        $rows = $products->getCollection()->map(fn (Product $p): array => $this->gridRow($p, $stock[(int) $p->id] ?? []))->all();

        return response()->streamDownload(static function () use ($rows, $columns): void {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('产品列表');
            $col = 1;
            foreach ($columns as $label) {
                $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($col++).'1', $label, DataType::TYPE_STRING);
            }
            foreach ($rows as $offset => $row) {
                $col = 1;
                foreach ($columns as $key => $label) {
                    $value = $row[$key] ?? null;
                    $sheet->setCellValueExplicit(
                        Coordinate::stringFromColumnIndex($col++).($offset + 2),
                        $value === null || $value === '' ? '—' : (string) $value,
                        DataType::TYPE_STRING
                    );
                }
            }
            $last = Coordinate::stringFromColumnIndex(count($columns));
            $sheet->getStyle('A1:'.$last.'1')->getFont()->setBold(true);
            $sheet->freezePane('C2');
            $sheet->setAutoFilter('A1:'.$last.(count($rows) + 1));
            foreach (range(1, count($columns)) as $index) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setWidth(24);
            }
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 'BYL-products-'.now()->format('Ymd-His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function productListPageSize(Request $request): int
    {
        $requested = (string) $request->query('per_page', '50');
        if ($requested === 'all') return max(1, $this->products->totalCount());
        return in_array($requested, ['20', '50', '100'], true) ? (int) $requested : 50;
    }

    private function loadGridRelations(\Illuminate\Database\Eloquent\Collection $products): void
    {
        $connection = (string) config('services.woocommerce.connection_key', 'beyourlover.com');
        $products->load([
            'packagingProfile',
            'providerSnapshots' => fn ($query) => $query->where('provider', 'woocommerce')->where('connection_key', $connection),
            'media' => fn ($query) => $query->where('provider', 'woocommerce')->where('connection_key', $connection)->where('is_primary', true),
        ]);
    }

    /** @param array<int,array<string,mixed>> $warehouses */
    private function gridRow(Product $p, array $warehouses): array
    {
        $snapshot = $p->providerSnapshots->first();
        $media = $p->media->first();
        // Partial or unverified warehouses must never be mistaken for zero stock.
        $stockKnown = $warehouses !== [] && collect($warehouses)->every(
            fn (array $row): bool => $row['quantity_available'] !== null
        );
        $dimensions = $p->length_cm !== null && $p->width_cm !== null && $p->height_cm !== null
            ? $p->length_cm.'×'.$p->width_cm.'×'.$p->height_cm
            : null;

        return [
            'id' => (int) $p->id,
            'sku' => $p->sku,
            'name' => $p->name,
            'source_type' => $p->source_type,
            'source' => $p->source_type === 'manual' ? '手动' : 'Woo',
            'is_active' => (bool) $p->is_active,
            'status' => $p->is_active ? '启用' : '停用',
            'gtin' => $p->gtin,
            'label_description' => $p->label_description,
            'image_url' => $this->gridImageUrl($media),
            'woo_url' => $snapshot?->permalink,
            'units_per_carton' => $p->packagingProfile?->units_per_carton,
            // These are PRODUCT dimensions, not packaged dimensions.
            'dimensions_cm' => $dimensions,
            'weight_kg' => $p->weight_kg,
            'shipping_weight_kg' => $p->shipping_weight_kg,
            'unit' => $p->unit,
            'standard_production_cost' => $p->standard_production_cost,
            'standard_packaging_cost' => $p->standard_packaging_cost,
            'cost_currency' => $p->cost_currency,
            'sale_price' => $p->sale_price,
            'regular_price' => $p->regular_price,
            'available_stock' => $stockKnown ? array_sum(array_column($warehouses, 'quantity_available')) : null,
            // Tencent sheet-only fields lack canonical mappings in Product OS.
            'cnf' => null,
            'material_name' => null,
            'packaging_dimensions' => null,
            'brand' => null,
        ];
    }

    /** Use canonical local media only when present; otherwise show the recorded Woo image. */
    private function gridImageUrl(?ProductMedia $media): ?string
    {
        if ($media === null) return null;
        if ($media->storage_path && Storage::disk('local')->exists($media->storage_path)) {
            return route('products.media.show', $media->id);
        }
        // Normalize the original URL BEFORE parse_url(): PHP replaces non-ASCII
        // bytes inside decoded paths, losing historic Chinese image filenames.
        $source = preg_replace_callback(
            '/[^\\x21-\\x7E]/u',
            static fn (array $match): string => rawurlencode($match[0]),
            trim((string) $media->source_url)
        );
        if ($source === null || ! filter_var($source, FILTER_VALIDATE_URL)) return null;
        $parts = parse_url($source);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') return null;
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) return null;
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host !== 'beyourlover.com' && ! str_ends_with($host, '.beyourlover.com')) return null;
        return $source;
    }

    /** @return array<string,string> */
    private function measurementDimension(array $m, string $prefix): ?string
    {
        $keys = [$prefix.'_length_cm', $prefix.'_width_cm', $prefix.'_height_cm'];
        if (!collect($keys)->every(fn ($key) => is_numeric($m[$key] ?? null)
            && (float) $m[$key] > 0)) return null;
        return implode(' × ', array_map(fn ($key) => (string) $m[$key], $keys)).' cm';
    }

    private function measurementVolume(array $m, string $prefix): ?float
    {
        $keys = [$prefix.'_length_cm', $prefix.'_width_cm', $prefix.'_height_cm'];
        if (!collect($keys)->every(fn ($key) => is_numeric($m[$key] ?? null)
            && (float) $m[$key] > 0)) return null;
        return round(array_product(array_map(fn ($key) => (float) $m[$key], $keys)) / 1000, 3);
    }

    private function parameterCoverageCounts(): array
    {
        $accepted = fn ($q) => $q->whereIn('status', ['submitted','approved']);
        $unfilled = Product::query()->whereDoesntHave('parameterSubmissions', $accepted);
        return [
            '全部' => Product::query()->count(),
            '已填' => Product::query()->whereHas('parameterSubmissions', $accepted)->count(),
            '必填' => (clone $unfilled)->whereHas('parameterTask', fn ($q) =>
                $q->where('enabled', true)->where('is_required', true))->count(),
            '待填' => (clone $unfilled)->whereHas('parameterTask', fn ($q) =>
                $q->where('enabled', true)->where('is_required', false))->count(),
            '其他' => (clone $unfilled)->whereDoesntHave('parameterTask', fn ($q) =>
                $q->where('enabled', true))->count(),
        ];
    }

    private static function gridExportColumns(): array
    {
        return [
            'status' => '状态', 'sku' => 'SKU', 'cnf' => 'CNF（待接入）',
            'material_name' => '物料名称（待接入）', 'name' => '产品名称',
            'image_url' => '图片链接', 'woo_url' => '官网链接',
            'units_per_carton' => '标准装箱数', 'packaging_dimensions' => '包装尺寸（待接入）',
            'weight_kg' => '商品重量 kg', 'regular_price' => '原价',
            'sale_price' => '售价', 'brand' => '品牌（待接入）',
            'available_stock' => '可用库存（已核实仓库）',
            'gtin' => 'GTIN', 'label_description' => '蓝标描述',
            'dimensions_cm' => '商品尺寸 cm', 'shipping_weight_kg' => '运重 kg',
            'standard_production_cost' => '标准生产成本',
            'standard_packaging_cost' => '标准包材成本',
            'cost_currency' => '成本币种', 'unit' => '单位', 'source' => '来源',
        ];
    }

    public function create(): Response
    {
        return Inertia::render('Products/Create');
    }

    public function show(Request $request, int $id): Response|RedirectResponse
    {
        $product = $this->products->findById($id);
        if ($product === null) {
            return redirect()->route('products.list');
        }

        $orderData = $this->orderProjection->forProduct($id);
        $inventoryRows = $this->inventory->forProductIds([$id])[$id] ?? [];
        $weight = $product->weight_evidence_id ? $this->weightEvidence->findById((int) $product->weight_evidence_id) : null;
        $shippingWeight = $product->shipping_weight_evidence_id ? $this->weightEvidence->findById((int) $product->shipping_weight_evidence_id) : null;
        $connection = (string) config('services.woocommerce.connection_key', 'beyourlover.com');
        $configuredProductReference = collect($this->externalRefs->forProduct($id))->first(fn (array $reference): bool => $reference['provider'] === 'woocommerce' && $reference['connection_key'] === $connection && $reference['reference_type'] === 'product');
        $snapshot = ProductProviderSnapshot::query()->where(['product_id' => $id, 'provider' => 'woocommerce', 'connection_key' => $connection])->first();
        $media = ProductMedia::query()->where(['product_id' => $id, 'provider' => 'woocommerce', 'connection_key' => $connection])->orderBy('position')->get();
        $recentProductRuns = \App\Modules\Sync\Infrastructure\Models\SyncRunItem::query()->with('run')->where('product_id', $id)->where('operation', 'woocommerce.product_inbound')->latest('id')->limit(10)->get()->map(fn ($item): array => ['status' => $item->status?->value ?? $item->status, 'result_code' => $item->result_code, 'error_message' => $item->error_message, 'completed_at' => $item->completed_at?->toIso8601String(), 'run_id' => $item->sync_run_id])->all();

        $canManageProduct = Auth::user()?->can(AuthorizationKeys::ProductsOperate->value) ?? false;
        $parameterTask = ProductParameterTask::query()->where('product_id', $id)->first();
        $parameterSubmissions = $canManageProduct
            ? ProductParameterSubmission::query()->where('product_id', $id)->with('media')->latest('id')->limit(15)->get()
            : collect();
        $approvedParameterMeasurements = [];
        // Overlay latest confirmed non-empty values, rather than hiding previously
        // approved fields when a later submission updates only one parameter.
        ProductParameterSubmission::query()->where('product_id', $id)
            ->where('status', 'approved')->orderByDesc('reviewed_at')->orderByDesc('id')
            ->get(['measurements'])->each(function ($record) use (&$approvedParameterMeasurements): void {
                foreach ($record->measurements ?? [] as $key => $value) {
                    if (!array_key_exists($key, $approvedParameterMeasurements) && $value !== null && $value !== '') {
                        $approvedParameterMeasurements[$key] = $value;
                    }
                }
            });

        return Inertia::render('Products/Show', [
            'product' => [
                'id' => (int) $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'source_type' => $product->source_type,
                'status' => $product->is_active ? 'active' : 'inactive',
                'product_type' => $product->product_type,
                'woocommerce_product_id' => $product->woocommerce_product_id,
                'woo_url' => $snapshot?->permalink,
                'image_url' => $media->firstWhere('is_primary', true) ? route('products.media.show', $media->firstWhere('is_primary', true)->id) : null,
                'woo_sync_status' => $snapshot ? 'synced' : ($configuredProductReference ? 'not_synced' : 'not_configured'),
                'sale_price' => $product->sale_price,
                'regular_price' => $product->regular_price,
                'weight_kg' => $product->weight_kg,
                'shipping_weight_kg' => $product->shipping_weight_kg,
                'weight_evidence_source_type' => $weight?->getAttribute('source_type'),
                'weight_evidence_source_reference' => $weight?->getAttribute('source_reference'),
                'shipping_weight_evidence_source_type' => $shippingWeight?->getAttribute('source_type'),
                'shipping_weight_evidence_source_reference' => $shippingWeight?->getAttribute('source_reference'),
                'length_cm' => $product->length_cm,
                'width_cm' => $product->width_cm,
                'height_cm' => $product->height_cm,
                'standard_production_cost' => $product->standard_production_cost,
                'standard_packaging_cost' => $product->standard_packaging_cost,
                'standard_purchase_cost' => $product->standard_purchase_cost,
                'cost_currency' => $product->cost_currency,
                'cost_note' => $product->cost_note,
                'country_of_origin' => $product->country_of_origin,
                'hs_code' => $product->hs_code,
            ],
            'externalReferences' => $this->externalRefs->forProduct($id),
            'woo' => $snapshot ? ['provider_status' => $snapshot->provider_status, 'categories' => $snapshot->category_names ?? [], 'description' => $snapshot->description_text, 'short_description' => $snapshot->short_description_text, 'variation_count' => $snapshot->variation_count, 'provider_created_at' => $snapshot->provider_created_at?->toIso8601String(), 'provider_modified_at' => $snapshot->provider_modified_at?->toIso8601String(), 'last_synced_at' => $snapshot->last_synced_at?->toIso8601String(), 'connection_key' => $snapshot->connection_key] : null,
            'media' => $media->map(fn (ProductMedia $item): array => ['id' => $item->id, 'url' => route('products.media.show', $item->id), 'alt_text' => $item->alt_text, 'position' => $item->position, 'is_primary' => $item->is_primary])->values()->all(),
            'wooSyncHistory' => $recentProductRuns,
            'skuAliases' => $this->skuAliases->listForProduct($id),
            'sales' => [
                ...$orderData['summary'],
                'revenue_by_currency' => $orderData['revenue_by_currency'],
                'recent_orders' => $orderData['recent_orders'],
            ],
            'inventory' => $inventoryRows,
            'shipments' => $this->shipmentProjection->forProduct($id),
            'collaborations' => $this->kolProjection->forProduct($id),
            'kolShipping' => $this->kolProjection->shippingSummaryForProduct($id),
            'audit' => $this->activityProjection->forProduct($id),
            'parameterTask' => $canManageProduct && $parameterTask ? [
                'id' => $parameterTask->id, 'enabled' => $parameterTask->enabled,
                'is_required' => $parameterTask->is_required,
                'assigned_user_id' => $parameterTask->assigned_user_id,
                'required_fields' => $parameterTask->required_fields ?? [],
            ] : null,
            'parameterSubmissions' => $parameterSubmissions->map(fn ($r) => [
                'id' => $r->id, 'status' => $r->status,
                'created_by' => $r->created_by, 'review_note' => $r->review_note,
                'submitted_at' => $r->submitted_at?->toIso8601String(),
                'measurements' => $r->measurements ?? [],
                'accessories' => $r->accessories ?? [],
                'notes' => $r->notes,
                'media' => $r->media->map(fn ($m) => ['id' => $m->id,
                    'name' => $m->original_name,
                    'url' => route('products.parameter.media', $m->id)])->all(),
            ])->all(),
            'approvedParameterMeasurements' => $approvedParameterMeasurements,
            'approvedAccessories' => ProductParameterAccessory::query()->where('product_id', $id)
                ->orderBy('id')->get(['name', 'quantity', 'unit', 'notes'])->toArray(),
            'factoryUserOptions' => $canManageProduct
                ? User::query()->where('is_active', true)
                    ->where(function ($query): void {
                        $query->whereHas('roles.permissions', fn ($p) => $p->where('key', 'products.parameters.submit'))
                            ->orWhereHas('customPermissions', fn ($p) => $p->where('key', 'products.parameters.submit'));
                    })->orderBy('name')->get(['id', 'name'])->toArray()
                : [],
            'canOperate' => Auth::user()?->can(AuthorizationKeys::ProductsOperate->value) ?? false,
            'canSync' => Auth::user()?->can(AuthorizationKeys::SyncOperate->value) ?? false,
            'hasWooProductReference' => $configuredProductReference !== null,
        ]);
    }

    public function media(int $media): \Symfony\Component\HttpFoundation\StreamedResponse|JsonResponse
    {
        $item = ProductMedia::query()->whereKey($media)->first();
        if ($item === null || ! $item->product()->exists() || ! Storage::disk('local')->exists($item->storage_path)) abort(404);
        return Storage::disk('local')->response($item->storage_path, null, ['Content-Type' => $item->mime_type, 'Cache-Control' => 'private, no-store']);
    }

    public function syncWooCommerce(Request $request, int $id): RedirectResponse
    {
        $reference = \App\Modules\Products\Infrastructure\Models\ProductExternalReference::query()->where(['product_id' => $id, 'provider' => 'woocommerce', 'connection_key' => config('services.woocommerce.connection_key')])->where('reference_type', 'product')->first();
        if ($reference === null) return back()->with('error', '当前产品没有配置 WooCommerce 产品引用。');
        try {
            $result = $this->wooInbound->sync($request->user(), false, (int) $reference->reference);
            $status = $result['run']->status instanceof \BackedEnum ? $result['run']->status->value : (string) $result['run']->status;
            if ($status !== 'completed') return back()->with('error', 'WooCommerce 产品同步未完成，请查看同步历史。');
        } catch (\Throwable $e) {
            return back()->with('error', 'WooCommerce 产品同步失败：'.$e->getMessage());
        }
        return back()->with('success', 'WooCommerce 产品已刷新。');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:128', 'unique:products,sku'],
            'label_sku' => ['nullable', 'string', 'max:128', 'unique:products,label_sku'],
            'label_description' => ['nullable', 'string', 'max:512'],
            'name' => ['required', 'string', 'max:512'],
            'product_type' => ['sometimes', 'string', 'max:64'],
            'gtin' => ['nullable', 'string', 'max:128'],
            'unit' => ['nullable', 'string', 'max:32'],
            'is_active' => ['sometimes', 'boolean'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'regular_price' => ['nullable', 'numeric', 'min:0'],
            'shipping_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'length_cm' => ['nullable', 'numeric', 'min:0'],
            'width_cm' => ['nullable', 'numeric', 'min:0'],
            'height_cm' => ['nullable', 'numeric', 'min:0'],
            'country_of_origin' => ['nullable', 'string', 'size:2'],
            'hs_code' => ['nullable', 'string', 'max:32'],
            'standard_production_cost' => ['nullable', 'numeric', 'min:0'],
            'standard_packaging_cost' => ['nullable', 'numeric', 'min:0'],
            'standard_purchase_cost' => ['nullable', 'numeric', 'min:0'],
            'cost_currency' => ['nullable', 'string', 'size:3'],
            'cost_note' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $product = $this->products->createManual($data);
        } catch (\RuntimeException $e) {
            $field = str_contains($e->getMessage(), '蓝标') ? 'label_sku' : 'sku';
            return back()->withErrors([$field => $e->getMessage()])->withInput();
        }

        return redirect()->route('products.edit', $product->id)->with('success', '产品已创建');
    }

    public function edit(int $id): Response|RedirectResponse
    {
        $product = $this->products->findById($id);
        if ($product === null) {
            return redirect()->route('products.list');
        }

        $weightEvidence = $product->weight_evidence_id
            ? $this->weightEvidence->findById((int) $product->weight_evidence_id)
            : null;
        $shippingWeightEvidence = $product->shipping_weight_evidence_id
            ? $this->weightEvidence->findById((int) $product->shipping_weight_evidence_id)
            : null;

        return Inertia::render('Products/Edit', [
            'product' => [
                'id' => $product->id,
                'woocommerce_product_id' => $product->woocommerce_product_id,
                'source_type' => $product->source_type,
                'product_type' => $product->product_type,
                'sku' => $product->sku,
                'label_sku' => $product->label_sku,
                'label_description' => $product->label_description,
                'gtin' => $product->gtin,
                'name' => $product->name,
                'unit' => $product->unit,
                'is_active' => $product->is_active,
                'weight_kg' => $product->weight_kg,
                'weight_evidence_id' => $product->weight_evidence_id,
                'weight_evidence_source_type' => $weightEvidence?->getAttribute('source_type'),
                'sale_price' => $product->sale_price,
                'regular_price' => $product->regular_price,
                'shipping_weight_kg' => $product->shipping_weight_kg,
                'shipping_weight_evidence_id' => $product->shipping_weight_evidence_id,
                'shipping_weight_evidence_source_type' => $shippingWeightEvidence?->getAttribute('source_type'),
                'length_cm' => $product->length_cm,
                'width_cm' => $product->width_cm,
                'height_cm' => $product->height_cm,
                'country_of_origin' => $product->country_of_origin,
                'hs_code' => $product->hs_code,
                'standard_production_cost' => $product->standard_production_cost,
                'standard_packaging_cost' => $product->standard_packaging_cost,
                'standard_purchase_cost' => $product->standard_purchase_cost,
                'cost_currency' => $product->cost_currency,
                'cost_note' => $product->cost_note,
                'notes' => $product->notes,
                'external_references' => $this->externalRefs->forProduct((int) $product->id),
            ],
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $product = $this->products->findById($id);
        if ($product === null) {
            return redirect()->route('products.list');
        }

        $data = $request->validate([
            'sku' => ['nullable', 'string', 'max:128', 'unique:products,sku,'.$id],
            'label_sku' => ['nullable', 'string', 'max:128', Rule::unique('products', 'label_sku')->ignore($id)],
            'label_description' => ['nullable', 'string', 'max:512'],
            'name' => ['required', 'string', 'max:512'],
            'product_type' => ['sometimes', 'string', 'max:64'],
            'gtin' => ['nullable', 'string', 'max:128'],
            'unit' => ['nullable', 'string', 'max:32'],
            'is_active' => ['sometimes', 'boolean'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'regular_price' => ['nullable', 'numeric', 'min:0'],
            'shipping_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'length_cm' => ['nullable', 'numeric', 'min:0'],
            'width_cm' => ['nullable', 'numeric', 'min:0'],
            'height_cm' => ['nullable', 'numeric', 'min:0'],
            'country_of_origin' => ['nullable', 'string', 'size:2'],
            'hs_code' => ['nullable', 'string', 'max:32'],
            'standard_production_cost' => ['nullable', 'numeric', 'min:0'],
            'standard_packaging_cost' => ['nullable', 'numeric', 'min:0'],
            'standard_purchase_cost' => ['nullable', 'numeric', 'min:0'],
            'cost_currency' => ['nullable', 'string', 'size:3'],
            'cost_note' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $this->products->updateProduct($product, $data);
        } catch (\RuntimeException $e) {
            $field = str_contains($e->getMessage(), '蓝标') ? 'label_sku' : 'sku';
            return back()->withErrors([$field => $e->getMessage()])->withInput();
        }

        return redirect()->route('products.edit', $id)->with('success', '产品已更新');
    }

    public function destroy(int $id): RedirectResponse
    {
        $product = $this->products->findById($id);
        if ($product !== null) {
            $this->products->deleteProduct($product);
        }

        return redirect()->route('products.list')->with('success', '产品已删除');
    }

    public function importPage(): Response
    {
        return Inertia::render('Products/Import');
    }

    public function importTemplate(): \Illuminate\Http\Response
    {
        $csv = "\xEF\xBB\xBF".'"ID","Type","SKU","GTIN, UPC, EAN, or ISBN","Name","Weight (kg)","Sale price","Regular price","Published","Parent"'."\r\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="product-import-csv-template.csv"',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    public function importStore(Request $request): Response
    {
        if ($request->input('action') === 'confirm') {
            $token = (string) $request->input('preview_token');
            $artifact = $request->session()->get("products.import.{$token}");
            if (! is_array($artifact) || ! isset($artifact['candidates']) || ! hash_equals((string) ($artifact['user_id'] ?? ''), (string) Auth::id())) {
                return Inertia::render('Products/Import', ['result' => ['invalid' => 1, 'errors' => ['预览已失效，请重新上传 CSV'], 'canConfirm' => false]]);
            }
            if (($artifact['can_confirm'] ?? false) !== true) {
                return Inertia::render('Products/Import', ['result' => [...($artifact['result'] ?? []), 'canConfirm' => false], 'previewToken' => $token]);
            }
            try {
                $result = $this->products->confirm($artifact['candidates']);
            } catch (\RuntimeException $exception) {
                return Inertia::render('Products/Import', ['result' => ['invalid' => 0, 'identityConflicts' => 1, 'errors' => [$exception->getMessage()], 'canConfirm' => false], 'previewToken' => $token]);
            }
            $request->session()->forget("products.import.{$token}");
            return Inertia::render('Products/Import', ['result' => $result->toArray(), 'confirmed' => true]);
        }

        $request->validate(['csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']]);
        $preview = $this->products->importFromUpload((string) $request->file('csv_file')->getRealPath());
        $token = (string) Str::uuid();
        $request->session()->put("products.import.{$token}", ['user_id' => (string) Auth::id(), 'candidates' => $preview['candidates'], 'can_confirm' => $preview['result']->toArray()['canConfirm'], 'result' => $preview['result']->toArray()]);

        return Inertia::render('Products/Import', [
            'result' => $preview['result']->toArray(),
            'previewToken' => $token,
        ]);
    }

    public function skuSearch(Request $request): JsonResponse
    {
        // This lookup does not confer access to the full Products module.
        abort_unless(
            $request->user()?->can('products.view') || $request->user()?->can('orders.create'),
            403,
        );
        $query = trim((string) $request->input('q', ''));
        $results = $this->products->searchSkus($query);

        return response()->json($results);
    }
}
