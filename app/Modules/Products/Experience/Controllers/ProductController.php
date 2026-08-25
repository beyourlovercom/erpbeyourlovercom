<?php

namespace App\Modules\Products\Experience\Controllers;

use App\Modules\Products\Application\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ProductController
{
    public function __construct(private readonly ProductService $products) {}

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
                'product_type' => $p->product_type,
                'sku' => $p->sku,
                'gtin' => $p->gtin,
                'name' => $p->name,
                'weight_kg' => $p->weight_kg,
                'sale_price' => $p->sale_price,
                'regular_price' => $p->regular_price,
            ])->toArray(),
            'filters' => $request->only(['search']),
        ]);
    }

    public function skuSearch(Request $request): JsonResponse
    {
        $query = trim((string) $request->input('q', ''));
        if ($query === '') {
            return response()->json([]);
        }
        $results = $this->products->searchSkus($query);

        return response()->json($results);
    }
}
