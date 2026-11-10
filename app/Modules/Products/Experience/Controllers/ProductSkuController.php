<?php

namespace App\Modules\Products\Experience\Controllers;

use App\Modules\Products\Application\ProductService;
use App\Modules\Products\Application\SkuAliasService;
use App\Modules\Shell\Application\GlobalGridPilot;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ProductSkuController
{
    public function __construct(
        private readonly ProductService $products,
        private readonly SkuAliasService $aliases,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['search']);
        $pilot = GlobalGridPilot::enabled($request);
        $requestedPerPage = (int) $request->query('per_page', 50);
        $perPage = $pilot && in_array($requestedPerPage, [20, 50, 100], true)
            ? $requestedPerPage : 50;
        $products = $this->products->list($filters, $perPage);
        // A single count query for the current page, not one query per SKU.
        $products->getCollection()->loadCount('skuAliases');

        return Inertia::render('Products/Skus', [
            'products' => $products->through(fn ($p): array => [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'is_active' => $p->is_active,
                'source_type' => $p->source_type,
                'alias_count' => $p->sku_aliases_count,
            ])->toArray(),
            'filters' => $filters,
            'globalGridPilotAvailable' => GlobalGridPilot::available(),
            'globalGridPilotEnabled' => $pilot,
        ]);
    }
}
