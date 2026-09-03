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
use App\Modules\Products\Infrastructure\Models\ProductMedia;
use App\Modules\Products\Infrastructure\Models\ProductProviderSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    public function index(Request $request): Response
    {
        $products = $this->products->list($request->only(['search']));

        return Inertia::render('Products/Index', [
            'products' => $products->through(fn ($p): array => [
                'id' => $p->id,
                'woocommerce_product_id' => $p->woocommerce_product_id,
                'source_type' => $p->source_type,
                'product_type' => $p->product_type,
                'sku' => $p->sku,
                'name' => $p->name,
                'unit' => $p->unit,
                'is_active' => $p->is_active,
                'shipping_weight_kg' => $p->shipping_weight_kg,
                'standard_production_cost' => $p->standard_production_cost,
                'standard_packaging_cost' => $p->standard_packaging_cost,
                'cost_currency' => $p->cost_currency,
                'sale_price' => $p->sale_price,
                'regular_price' => $p->regular_price,
            ])->toArray(),
            'filters' => $request->only(['search']),
            'canOperate' => Auth::user()?->can(AuthorizationKeys::ProductsOperate->value) ?? false,
        ]);
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
            return back()->withErrors(['sku' => $e->getMessage()])->withInput();
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
            return back()->withErrors(['sku' => $e->getMessage()])->withInput();
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
        $query = trim((string) $request->input('q', ''));
        $results = $this->products->searchSkus($query);

        return response()->json($results);
    }
}
