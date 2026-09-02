<?php

namespace App\Modules\Products\Experience\Controllers;

use App\Modules\Identity\Application\AuthorizationKeys;
use App\Modules\Products\Application\ProductService;
use App\Modules\Products\Application\ProductWeightEvidenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class ProductController
{
    public function __construct(
        private readonly ProductService $products,
        private readonly ProductWeightEvidenceService $weightEvidence,
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
        $csv = "\xEF\xBB\xBF".'"ID","Type","SKU","GTIN, UPC, EAN, or ISBN","Name","Weight (kg)","Sale price","Regular price","Published"'."\r\n";

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
