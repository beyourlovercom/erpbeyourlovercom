<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Logistics\Application\FulfillmentPlanCostEstimator;
use App\Modules\Products\Application\PackagingPresetCatalog;

/** B3: same route/version/date/handling and identical SKU quantities, via A4 only.
 * Internal read-only service. Caller must authorize access and establish eligibility.
 */
final class PackagingOptimizationQuoteComparison
{
    public function __construct(
        private readonly FulfillmentPlanCostEstimator $estimator,
        private readonly PackagingPresetCatalog $presets,
    ) {}

    public function compare(array $route, array $current, array $candidates, bool $draft = false): array
    {
        $context = array_intersect_key($route, array_flip(['version_id', 'warehouse_code', 'carrier', 'service', 'country', 'postal_code', 'as_of', 'handling_policy']));
        $baseline = $this->normalize($current);
        $old = $baseline === null ? ['status' => 'unavailable', 'reason' => 'invalid_package_composition'] : $this->quote($context, $baseline['packages'], $draft);
        $rows = [];
        foreach ($this->presets->keys() as $key) {
            $candidate = $this->normalize($candidates[$key] ?? []);
            $new = $candidate === null ? ['status' => 'unavailable', 'reason' => 'missing_or_invalid_candidate']
                : ($baseline === null || $baseline['items'] !== $candidate['items']
                    ? ['status' => 'unavailable', 'reason' => 'different_item_composition']
                    : $this->quote($context, $candidate['packages'], $draft));
            $moneyKey = $draft ? 'reference_total_cny' : 'total_cny';
            $status = $draft ? 'draft_estimate' : 'ready';
            $comparable = ($old['status'] ?? null) === $status && ($new['status'] ?? null) === $status
                && is_numeric($old[$moneyKey] ?? null) && is_numeric($new[$moneyKey] ?? null)
                && $this->sameFx($old['fx'] ?? [], $new['fx'] ?? []);
            $delta = $comparable ? round((float) $old[$moneyKey] - (float) $new[$moneyKey], 8) : null;
            $rows[$key] = [
                'quote' => $new,
                'status' => $comparable ? $status : 'unavailable',
                'reason' => $comparable ? null : (($old['status'] ?? null) !== $status ? ($old['reason'] ?? 'baseline_unavailable') : (($new['status'] ?? null) !== $status ? ($new['reason'] ?? 'candidate_unavailable') : 'fx_not_comparable')),
                'logistics_saving_cny' => $draft ? null : $delta,
                'reference_transport_saving_cny' => $draft ? $delta : null,
                'eligible_for_ranking' => ! $draft && $comparable,
                'estimate_only' => true,
            ];
        }
        return ['mode' => $draft ? 'draft_transport' : 'approved_version', 'baseline' => $old, 'candidates' => $rows,
            'version_id' => $context['version_id'] ?? null, 'as_of' => $context['as_of'] ?? null, 'estimate_only' => true];
    }

    /**
     * Economic target search at fixed length/width and unchanged estimated gross
     * weight. Evaluates only caller-supplied candidate heights (nearest first);
     * no claim of global optimum, protection or physical feasibility.
     */
    public function economicHeightTarget(array $route, array $currentPackage, string $method, array $heights, bool $draft = false): array
    {
        if (! in_array($method, $this->presets->keys(), true) || count($heights) > 100) {
            throw new \InvalidArgumentException('Unsupported method or search size');
        }
        $dims = $currentPackage['dimensions_cm'] ?? [];
        if (! is_array($dims) || ! array_is_list($dims) || count($dims) !== 3
            || count(array_filter($dims, fn ($v) => is_numeric($v) && is_finite((float)$v) && $v > 0)) !== 3) {
            return ['status'=>'missing_dimensions','target_height_cm'=>null,'eligible_for_ranking'=>false];
        }
        $heights = array_values(array_unique(array_map('floatval',array_filter($heights, fn ($h) => is_numeric($h) && is_finite((float)$h) && $h > 0 && $h < $dims[2]))));
        rsort($heights,SORT_NUMERIC);
        $tested = 0;
        $comparable = 0;
        foreach ($heights as $height) {
            $candidate = [...$currentPackage, 'dimensions_cm'=>[$dims[0],$dims[1],$height], 'weight_basis'=>
                ($currentPackage['weight_basis'] ?? null) === 'bare' ? 'bare' : 'estimated_packaged'];
            $r = $this->compare($route,[$currentPackage],[$method=>[$candidate]],$draft);
            $row = $r['candidates'][$method];
            $tested++;
            $delta = $draft ? $row['reference_transport_saving_cny'] : $row['logistics_saving_cny'];
            if ($delta !== null) {
                $comparable++;
                if ($delta > 0) {
                    return ['status'=>'candidate','target_height_cm'=>$height,'tested_count'=>$tested,'comparison'=>$row,
                        'weight_held_constant'=>true,'protection_verified'=>false,'eligible_for_ranking'=>false,'estimate_only'=>true];
                }
            }
        }
        return ['status'=>$comparable ? 'no_saving_in_tested_heights' : 'quote_unavailable','target_height_cm'=>null,
            'tested_count'=>$tested,'comparable_count'=>$comparable,'eligible_for_ranking'=>false,'estimate_only'=>true];
    }

    private function quote(array $route, array $packages, bool $draft): array
    {
        if (! in_array($route['handling_policy'] ?? null, ['supplier','internal'], true)) {
            return ['status'=>'unavailable','reason'=>'handling_policy_unconfirmed'];
        }
        $request = [...$route, 'packages' => $packages];
        return $draft ? $this->estimator->quoteDraftTransport($request) : $this->estimator->quoteVersioned($request);
    }

    private function normalize(mixed $packages): ?array
    {
        if (! is_array($packages) || ! $packages || count($packages) > 100) return null;
        $items = [];
        $normalized = [];
        foreach ($packages as $p) {
            if (! is_array($p) || ! is_array($p['items'] ?? null) || ! $p['items']) return null;
            $count = 0;
            foreach ($p['items'] as $id => $quantity) {
                if (! is_int($id) || $id <= 0 || ! is_int($quantity) || $quantity <= 0 || $quantity > 10000) return null;
                $count += $quantity;
                $items[$id] = ($items[$id] ?? 0) + $quantity;
            }
            if ($count > 10000) return null;
            $normalized[] = [...array_intersect_key($p, array_flip(['weight_kg', 'weight_basis', 'dimensions_cm', 'eligibility'])), 'item_count' => $count];
        }
        ksort($items);
        return ['items' => $items, 'packages' => $normalized];
    }

    private function sameFx(array $a, array $b): bool
    {
        if (! ($a['available'] ?? false) || ! ($b['available'] ?? false)) return false;
        if (($a['reference_date'] ?? null) !== ($b['reference_date'] ?? null) || ($a['stale'] ?? false) !== ($b['stale'] ?? false)) return false;
        $map = static function (array $fx): array {
            $r = [];
            foreach ($fx['conversions'] ?? [] as $c) $r[$c['original_currency']] = [$c['rate_to_cny'], $c['source']];
            ksort($r);
            return $r;
        };
        return $map($a) === $map($b);
    }
}
