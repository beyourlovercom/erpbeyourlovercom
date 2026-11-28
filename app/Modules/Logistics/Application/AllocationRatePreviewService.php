<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Inventory\Application\ProductPackagingStandardReader;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Application\ProductShippingWeightStandardReader;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Support\Facades\DB;

/** On-demand, read-only whole-order comparison. No routing, inventory, financial or provider writes. */
final class AllocationRatePreviewService
{
    public function __construct(private readonly FulfillmentPlanCostEstimator $estimator, private readonly GiftSkuResolutionService $gifts, private readonly ProductPackagingStandardReader $packaging, private readonly ProductShippingWeightStandardReader $shipping, private readonly VersionedRateQuoteService $versioned, private readonly CarrierRoutingPolicy $routing) {}

    public function preview(Order $order, ?string $confirmedScope): array
    {
        $order->loadMissing(['address', 'items']);
        $country = strtoupper(trim((string) $order->address?->country));
        $postal = trim((string) $order->address?->postal_code);
        $date = now('Asia/Shanghai')->toDateString();
        $base = ['country' => $country, 'as_of' => $date, 'estimate_only' => true, 'eligible_for_ranking' => false, 'measurement' => null, 'rows' => []];
        if (! preg_match('/^[A-Z]{2}$/D', $country)) {
            return [...$base, 'status' => 'unavailable', 'reason' => 'missing_destination'];
        }
        $items = [];
        foreach ($order->items as $item) {
            $id = $item->fulfillmentSourceProductId();
            if (! $id || $this->gifts->isGiftSku($item->source_sku ?: $item->sku) || (int) $item->quantity < 1) {
                return [...$base, 'status' => 'unavailable', 'reason' => 'unresolved_product'];
            }
            $items[$id] = ($items[$id] ?? 0) + (int) $item->quantity;
        }

        return $this->previewProducts($order, $items, $confirmedScope);
    }

    /** Only server-resolved physical product IDs and quantities may enter this method. */
    public function previewProducts(Order $order, array $items, ?string $confirmedScope = null, ?string $onlyWarehouse = null, bool $selectedPlan = false): array
    {
        $order->loadMissing('address');
        $country = strtoupper(trim((string) $order->address?->country));
        $postal = trim((string) $order->address?->postal_code);
        $date = now('Asia/Shanghai')->toDateString();
        $base = ['country' => $country, 'as_of' => $date, 'estimate_only' => true, 'eligible_for_ranking' => false, 'measurement' => null, 'rows' => []];
        if (! preg_match('/^[A-Z]{2}$/D', $country)) {
            return [...$base, 'status' => 'unavailable', 'reason' => 'missing_destination'];
        }
        if (! $items || array_sum($items) > 10000) {
            return [...$base, 'status' => 'unavailable', 'reason' => 'unresolved_product'];
        }
        $standards = $this->packaging->forProductIds(array_keys($items));
        $shipping = $this->shipping->forProductIds(array_keys($items));
        $products = Product::query()->whereIn('id', array_keys($items))->get(['id', 'weight_kg', 'has_battery', 'has_liquid'])->keyBy('id');
        $weight = 0.0;
        $length = $width = $height = 0.0;
        $missing = $missingDimensions = $estimated = [];
        $evidence = [];
        $positive = static fn ($v): bool => is_numeric($v) && is_finite((float) $v) && (float) $v > 0;
        foreach ($items as $id => $quantity) {
            $s = $standards[$id] ?? [];
            $product = $products->get($id);
            $kg = $s['packaged_weight_kg'] ?? null;
            $weightSource = 'approved_packaging';
            if (! $positive($kg)) {
                $kg = $shipping[$id]['shipping_weight_kg'] ?? null;
                $weightSource = 'adopted_shipping_weight';
                if (! $positive($kg)) {
                    $kg = $product?->weight_kg;
                    $weightSource = 'product_weight_plus_20_percent';
                    if ($positive($kg)) {
                        $kg = (float) $kg * 1.2;
                    }
                }
            }
            $dims = [$s['packaging_length_cm'] ?? null, $s['packaging_width_cm'] ?? null, $s['packaging_height_cm'] ?? null];
            $dimensionSource = 'approved_packaging';
            if (! $positive($kg)) {
                $missing[] = $id;
            } else {
                $weight += (float) $kg * $quantity;
            }
            if (count(array_filter($dims, $positive)) !== 3) {
                $missingDimensions[] = $id;
                $dimensionSource = 'missing';
            } else {
                $dims = array_map('floatval', $dims);
                rsort($dims, SORT_NUMERIC);
                $length = max($length, $dims[0]);
                $width = max($width, $dims[1]);
                $height += $dims[2] * $quantity;
            }
            if ($weightSource !== 'approved_packaging' || $dimensionSource !== 'approved_packaging') {
                $estimated[] = $id;
            }
            $evidence[] = ['product_id' => $id, 'quantity' => $quantity, 'weight_source' => $positive($kg) ? $weightSource : 'missing',
                'weight_kg' => $positive($kg) ? (float) $kg : null, 'dimension_source' => $dimensionSource,
                'dimensions_cm' => $dimensionSource === 'missing' ? null : $dims,
                'measurement_record_id' => $s['measurement_record_id'] ?? null,
                'shipping_evidence' => $weightSource === 'adopted_shipping_weight' ? ($shipping[$id] ?? null) : null];
        }
        $base['missing_product_ids'] = array_values(array_unique(array_merge($missing, $missingDimensions)));
        $base['measurement_evidence'] = $evidence;
        if ($missing) {
            return [...$base, 'status' => 'unavailable', 'reason' => 'missing_packaging_standard'];
        }
        $count = array_sum($items);
        $measurement = ['weight_kg' => round($weight, 6), 'dimensions_cm' => $missingDimensions ? null : [round($length, 4), round($width, 4), round($height, 4)],
            'item_count' => $count, 'basis' => $missingDimensions ? 'weight_only_reference' : ($estimated ? 'product_standard_estimate' : ($count === 1 ? 'approved_packaging' : 'stacked_packaging_estimate'))];
        $base['measurement'] = $measurement;
        // Only canonical actual warehouse codes; never map Speeda's US-LA to MorePlus.
        $warehouses = match ($country) {
            'US' => ['US-NY', 'US-LA', 'US-SM', 'US-AMZ', 'DE', 'FACTORY'],
            'CA' => ['CA', 'DE', 'FACTORY'],
            default => ['DE', 'FACTORY'],
        };
        if ($onlyWarehouse !== null) {
            $warehouses = [$onlyWarehouse];
        }
        // DE international offers may apply even outside Germany; never infer eligibility.
        // Pick current book versions BEFORE filtering by route, so deleted draft routes
        // cannot fall back to a superseded quote. Overlapping approved versions fail closed.
        $effective = DB::table('logistics_rate_versions')->whereIn('status', ['approved', 'draft'])
            ->whereNotNull('effective_from')->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date))
            ->orderByDesc('version_number')->orderByDesc('id')->get(['id', 'book_id', 'status']);
        $versionIds = $effective->where('status', 'approved')->pluck('id')
            ->concat($effective->where('status', 'draft')->groupBy('book_id')->map(fn ($book) => $book->first()->id))->values();
        $versions = DB::table('logistics_rate_versions as v')
            ->join('logistics_rate_components as c', 'c.version_id', '=', 'v.id')
            ->whereIn('v.id', $versionIds)
            ->whereIn('c.warehouse_code', $warehouses)->where('c.destination_country', $country)->where('c.component_type', 'transport')
            ->select('v.id', 'v.book_id', 'v.version_number', 'v.status', 'v.effective_from', 'c.warehouse_code', 'c.carrier', 'c.service')
            ->distinct()->orderByDesc('v.version_number')->orderByDesc('v.id')->get();
        $rows = [];
        foreach ($warehouses as $warehouse) {
            $routes = $versions->where('warehouse_code', $warehouse)->groupBy(fn ($v) => $v->carrier.'|'.$v->service);
            if ($routes->isEmpty()) {
                $rows[] = ['warehouse_code' => $warehouse, 'carrier' => null, 'service' => null, 'status' => 'unavailable', 'reason' => 'rate_scope_missing', 'amount_cny' => null, 'native_totals' => null, 'extra_item_usd' => null];

                continue;
            }
            foreach ($routes as $route) {
                $approved = $route->where('status', 'approved');
                $candidates = $approved->isNotEmpty() ? $approved : $route->where('status', 'draft');
                $v = $candidates->first();
                $row = ['warehouse_code' => $warehouse, 'carrier' => $v->carrier, 'service' => $v->service, 'version_id' => (int) $v->id, 'version_number' => (int) $v->version_number, 'version_status' => $v->status,
                    'status' => 'unavailable', 'reason' => null, 'amount_cny' => null, 'native_totals' => null, 'extra_item_usd' => $warehouse === 'FACTORY' ? null : max(0, $count - 1)];
                if ($candidates->count() !== 1) {
                    $rows[] = [...$row, 'reason' => 'rate_version_ambiguous'];

                    continue;
                }
                // This is an explicit hypothetical calculation, never a certification of stock/eligibility.
                // The user confirms applicability before a previously approved rate can return a calculated total.
                if (! $selectedPlan && $v->status === 'approved' && $confirmedScope !== $warehouse.'|'.$v->carrier.'|'.$v->service.'|'.$v->id) {
                    $rows[] = [...$row, 'reason' => 'eligibility_unconfirmed'];

                    continue;
                }
                $request = ['version_id' => (int) $v->id, 'warehouse_code' => $warehouse, 'carrier' => $v->carrier, 'service' => $v->service, 'country' => $country, 'postal_code' => $postal, 'as_of' => $date, 'handling_policy' => 'internal',
                    'packages' => [['weight_kg' => $measurement['weight_kg'], 'weight_basis' => $measurement['basis'] === 'approved_packaging' ? 'measured_packaged' : 'estimated_packaged', 'dimensions_cm' => $measurement['dimensions_cm'], 'item_count' => $count, 'eligibility' => 'confirmed']]];
                $quote = $missingDimensions ? $this->versioned->quoteWeightOnlyReference($request, $v->status === 'draft') : ($v->status === 'approved' ? $this->estimator->quoteVersioned($request) : $this->estimator->quoteDraftTransport($request));
                $partial = $quote['status'] === 'weight_only_reference';
                $draft = $quote['status'] === 'draft_estimate';
                $ready = in_array($quote['status'], ['ready', 'fx_unavailable'], true);
                $row = [...$row, 'status' => $partial ? 'weight_only_reference' : ($draft ? 'draft_reference' : ($ready ? 'approved_reference' : 'unavailable')), 'reason' => $quote['reason'] ?? null,
                    'amount_cny' => ($draft || $partial) ? ($quote['reference_total_cny'] ?? null) : ($quote['total_cny'] ?? null),
                    'native_totals' => ($draft || $partial) ? ($quote['reference_native_totals'] ?? null) : ($quote['native_totals'] ?? null),
                    'components' => $quote['components'] ?? [], 'zone' => $quote['zone'] ?? null, 'fx' => $quote['fx'] ?? null,
                    'extra_item_usd' => ($ready || ($partial && $v->status === 'approved')) ? null : $row['extra_item_usd']];
                $rows[] = $row;
            }
        }

        $factoryRoute = $this->routing->factoryRoute($country);
        $factoryRef = null;
        if (($factoryRoute['carrier'] ?? null) === '4PX' && ($factoryRoute['service'] ?? null) === 'SD经济挂号-带电') {
            $factoryRef = collect($rows)->first(fn ($row) => $row['warehouse_code'] === 'FACTORY' && $row['carrier'] === 'SPEEDA' && $row['service'] === 'sd_economy_battery');
        }

        $warehouseRows = $this->lowestWarehouseReferences($rows, $products, $factoryRoute);

        return [...$base, 'status' => 'reference', 'reason' => null, 'rows' => $rows, 'warehouse_rows' => $warehouseRows, 'factory_default_reference' => $factoryRef];
    }

    /**
     * Display-only comparison. Do not change selected-plan routing or the full
     * rate contract. Unknown cargo classes and incomplete fees are not ranked
     * as confirmed shipping options.
     */
    private function lowestWarehouseReferences(array $rows, $products, array $factoryRoute): array
    {
        $battery = $products->contains(fn ($product) => $product->has_battery !== false);
        $liquid = $products->contains(fn ($product) => $product->has_liquid !== false);
        $result = [];
        foreach (collect($rows)->groupBy('warehouse_code') as $warehouse => $group) {
            $eligible = $group;
            if ($warehouse === 'FACTORY') {
                // Preserve frozen country-specific carrier overrides. Only
                // ordinary registered parcel channels have a known cargo class.
                $services = $liquid ? [] : ($battery
                    ? ['sd_standard_battery', 'sd_economy_battery']
                    : ['sd_standard_general', 'sd_economy_general', 'sd_standard_battery', 'sd_economy_battery']);
                $eligible = ($factoryRoute['carrier'] ?? null) === '4PX'
                    ? $group->filter(fn ($row) => $row['carrier'] === 'SPEEDA' && in_array($row['service'], $services, true))
                    : collect();
            }
            $priced = $eligible->filter(fn ($row) => is_numeric($row['amount_cny'] ?? null) && is_finite((float) $row['amount_cny'])
                && (float) $row['amount_cny'] >= 0 && in_array($row['status'], ['approved_reference', 'draft_reference', 'weight_only_reference'], true));
            // Compare like-for-like: complete reviewed costs first; otherwise
            // transport-only or weight-only references, never mix fee scopes.
            $tier = fn ($row) => match ($row['status']) {
                'approved_reference' => 0,
                'draft_reference' => 1,
                default => ($row['version_status'] ?? null) === 'approved' ? 2 : 3,
            };
            $best = $priced->sort(function ($a, $b) use ($tier) {
                return [$tier($a), (float) $a['amount_cny'], (string) $a['carrier'], (string) $a['service']]
                    <=> [$tier($b), (float) $b['amount_cny'], (string) $b['carrier'], (string) $b['service']];
            })->first();
            if ($best === null) {
                $best = $eligible->first(fn ($row) => ($row['reason'] ?? null) === 'eligibility_unconfirmed')
                    ?? $eligible->first()
                    ?? ['warehouse_code' => $warehouse, 'carrier' => null, 'service' => null, 'status' => 'unavailable', 'reason' => 'cargo_or_route_unverified', 'amount_cny' => null, 'native_totals' => null, 'extra_item_usd' => null];
            }
            $result[] = $best;
        }

        return $result;
    }
}
