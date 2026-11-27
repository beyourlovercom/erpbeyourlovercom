<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Inventory\Application\ProductPackagingStandardReader;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\DB;

/** On-demand, read-only whole-order comparison. No routing, inventory, financial or provider writes. */
final class AllocationRatePreviewService
{
    public function __construct(private readonly FulfillmentPlanCostEstimator $estimator, private readonly GiftSkuResolutionService $gifts, private readonly ProductPackagingStandardReader $packaging) {}

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
        if (! $items || array_sum($items) > 10000) {
            return [...$base, 'status' => 'unavailable', 'reason' => 'unresolved_product'];
        }
        $standards = $this->packaging->forProductIds(array_keys($items));
        $weight = 0.0;
        $length = 0.0;
        $width = 0.0;
        $height = 0.0;
        $missing = [];
        foreach ($items as $id => $quantity) {
            $s = $standards[$id] ?? [];
            $values = [$s['packaged_weight_kg'] ?? null, $s['packaging_length_cm'] ?? null, $s['packaging_width_cm'] ?? null, $s['packaging_height_cm'] ?? null];
            if (count(array_filter($values, fn ($v) => is_numeric($v) && is_finite((float) $v) && (float) $v > 0)) !== 4) {
                $missing[] = $id;

                continue;
            }
            $weight += (float) $values[0] * $quantity;
            $dims = array_map('floatval', array_slice($values, 1));
            rsort($dims, SORT_NUMERIC);
            $length = max($length, $dims[0]);
            $width = max($width, $dims[1]);
            $height += $dims[2] * $quantity;
        }
        if ($missing) {
            return [...$base, 'status' => 'unavailable', 'reason' => 'missing_packaging_standard', 'missing_product_ids' => $missing];
        }
        $count = array_sum($items);
        $measurement = ['weight_kg' => round($weight, 6), 'dimensions_cm' => [round($length, 4), round($width, 4), round($height, 4)],
            'item_count' => $count, 'basis' => $count === 1 ? 'approved_packaging' : 'stacked_packaging_estimate'];
        $base['measurement'] = $measurement;
        // Only canonical actual warehouse codes; never map Speeda's US-LA to MorePlus.
        $warehouses = match ($country) {
            'US' => ['US-NY', 'US-LA', 'US-SM', 'US-AMZ', 'DE', 'FACTORY'],
            'CA' => ['CA', 'DE', 'FACTORY'],
            default => ['DE', 'FACTORY'],
        };
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
                if ($v->status === 'approved' && $confirmedScope !== $warehouse.'|'.$v->carrier.'|'.$v->service.'|'.$v->id) {
                    $rows[] = [...$row, 'reason' => 'eligibility_unconfirmed'];

                    continue;
                }
                $request = ['version_id' => (int) $v->id, 'warehouse_code' => $warehouse, 'carrier' => $v->carrier, 'service' => $v->service, 'country' => $country, 'postal_code' => $postal, 'as_of' => $date, 'handling_policy' => 'internal',
                    'packages' => [['weight_kg' => $measurement['weight_kg'], 'weight_basis' => $count === 1 ? 'measured_packaged' : 'estimated_packaged', 'dimensions_cm' => $measurement['dimensions_cm'], 'item_count' => $count, 'eligibility' => 'confirmed']]];
                $quote = $v->status === 'approved' ? $this->estimator->quoteVersioned($request) : $this->estimator->quoteDraftTransport($request);
                $draft = $quote['status'] === 'draft_estimate';
                $ready = in_array($quote['status'], ['ready', 'fx_unavailable'], true);
                $row = [...$row, 'status' => $draft ? 'draft_reference' : ($ready ? 'approved_reference' : 'unavailable'), 'reason' => $quote['reason'] ?? null,
                    'amount_cny' => $draft ? ($quote['reference_total_cny'] ?? null) : ($quote['total_cny'] ?? null),
                    'native_totals' => $draft ? ($quote['reference_native_totals'] ?? null) : ($quote['native_totals'] ?? null),
                    'components' => $quote['components'] ?? [], 'zone' => $quote['zone'] ?? null,
                    'extra_item_usd' => $ready ? null : $row['extra_item_usd']];
                $rows[] = $row;
            }
        }

        return [...$base, 'status' => 'reference', 'reason' => null, 'rows' => $rows];
    }
}
