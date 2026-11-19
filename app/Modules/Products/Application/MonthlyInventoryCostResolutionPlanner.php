<?php

namespace App\Modules\Products\Application;

use App\Modules\Products\Application\Contracts\ProductRepository;

/**
 * Deterministic, read-only resolution plan for a monthly cost book.
 * It cannot create/update a product cost. Canonical confirmation runs separately.
 */
final class MonthlyInventoryCostResolutionPlanner
{
    public function __construct(private readonly ProductRepository $products) {}

    /**
     * @param  list<array<string,mixed>>  $sourceRows
     * @param  array<string,array<string,mixed>>  $decisions  keyed by group_key
     * @return array{groups:list<array<string,mixed>>,canonical_rows_by_month:array<string,list<array<string,string>>>,summary:array<string,int>}
     */
    public function resolve(array $sourceRows, array $decisions = []): array
    {
        $grouped = [];
        foreach ($sourceRows as $source) {
            $baseSku = trim((string) ($source['base_sku'] ?? ''));
            $month = (string) ($source['effective_from'] ?? '');
            $key = $month.'|'.($baseSku !== '' ? $baseSku : 'UNMATCHED|'.($source['source_variant_key'] ?? 'unknown'));
            $grouped[$key][] = $source;
        }

        $groups = [];
        $byMonth = [];
        $productCache = [];
        $summary = ['groups' => 0, 'ready' => 0, 'blocked' => 0, 'price_conflicts' => 0, 'erp_unmatched' => 0, 'needs_confirmation' => 0, 'manual_costs' => 0];
        foreach ($grouped as $key => $sources) {
            $first = $sources[0];
            $sku = trim((string) ($first['base_sku'] ?? ''));
            $month = (string) $first['effective_from'];
            $decision = $decisions[$key] ?? [];
            $reason = trim((string) ($decision['reason'] ?? ''));
            $manualProductId = trim((string) ($decision['product_id'] ?? ''));
            $chosenSourceKey = trim((string) ($decision['source_variant_key'] ?? ''));
            $manualCost = trim((string) ($decision['manual_cost_cny'] ?? ''));
            $distinctCosts = array_values(array_unique(array_column($sources, 'unit_cost')));
            $needsIdentityReview = $sku === '' || count(array_filter($sources, static fn (array $s) => ($s['extraction_status'] ?? '') !== 'extracted')) > 0;
            $blockers = [];

            if ($sku !== '' && ! array_key_exists($sku, $productCache)) {
                $matchKey = SkuMatchKey::compute($sku);
                $productCache[$sku] = array_values(array_filter(
                    $this->products->searchSkus($sku, 100),
                    static fn (array $p) => SkuMatchKey::compute((string) $p['sku']) === $matchKey,
                ));
            }
            $matches = $sku !== '' ? $productCache[$sku] : [];
            $product = null;
            if ($manualProductId !== '') {
                if (! ctype_digit($manualProductId) || (int) $manualProductId < 1) {
                    $blockers[] = 'invalid_manual_product_id';
                } else {
                    $found = $this->products->findById((int) $manualProductId);
                    if ($found === null || trim((string) $found->getAttribute('sku')) === '') {
                        $blockers[] = 'manual_product_missing';
                    } else {
                        $product = ['id' => (int) $found->getKey(), 'sku' => (string) $found->getAttribute('sku'), 'name' => (string) $found->getAttribute('name')];
                    }
                }
                if ($reason === '') {
                    $blockers[] = 'manual_mapping_reason_required';
                }
            } elseif (! $needsIdentityReview && count($matches) === 1) {
                $product = $matches[0];
            } elseif ($needsIdentityReview) {
                $blockers[] = 'sku_confirmation_required';
            } elseif (count($matches) === 0) {
                $blockers[] = 'erp_product_not_found';
            } else {
                $blockers[] = 'multiple_erp_sku_matches';
            }

            $selected = null;
            if ($chosenSourceKey !== '') {
                foreach ($sources as $source) {
                    if ($source['source_variant_key'] === $chosenSourceKey) {
                        $selected = $source;
                        break;
                    }
                }
                if ($selected === null) {
                    $blockers[] = 'selected_source_not_in_group';
                }
            } elseif (count($distinctCosts) === 1 || $manualCost !== '') {
                $selected = $first;
            } else {
                $blockers[] = 'source_price_conflict';
            }

            $cost = $selected['unit_cost'] ?? null;
            if ($manualCost !== '') {
                if (! preg_match('/^[0-9]{1,8}(?:\\.[0-9]{1,12})?$/D', $manualCost) || bccomp($manualCost, '0', 12) <= 0) {
                    $blockers[] = 'manual_cost_invalid';
                } else {
                    $cost = bcadd($manualCost, '0', 12);
                }
                if ($reason === '') {
                    $blockers[] = 'manual_cost_reason_required';
                }
            } elseif (count($distinctCosts) > 1 && $chosenSourceKey !== '' && $reason === '') {
                $blockers[] = 'price_conflict_reason_required';
            }
            $blockers = array_values(array_unique($blockers));
            $status = $blockers === [] ? 'ready' : 'blocked';
            if ($status === 'ready') {
                $byMonth[$month][] = [
                    'erp_product_id' => (string) $product['id'],
                    'erp_sku' => (string) $product['sku'],
                    'source_variant_key' => (string) $selected['source_variant_key'],
                    'unit_cost' => (string) $cost,
                    'currency' => 'CNY',
                ];
            }

            $groups[] = [
                'group_key' => $key,
                'effective_from' => $month,
                'base_sku' => $sku !== '' ? $sku : null,
                'status' => $status,
                'blockers' => $blockers,
                'product_match' => $product,
                'erp_candidates' => $matches,
                'source_costs' => $distinctCosts,
                'sources' => $sources,
                'selected_source_variant_key' => $selected['source_variant_key'] ?? null,
                'selected_unit_cost_cny' => $cost,
                'resolution_reason' => $reason,
                'manual_cost_applied' => $manualCost !== '' && $blockers === [],
            ];

            $summary['groups']++;
            $summary[$status]++;
            if (count($distinctCosts) > 1) {
                $summary['price_conflicts']++;
            }
            if ($sku === '' || count($matches) === 0) {
                $summary['erp_unmatched']++;
            }
            if ($needsIdentityReview) {
                $summary['needs_confirmation']++;
            }
            if ($manualCost !== '' && $status === 'ready') {
                $summary['manual_costs']++;
            }
        }
        ksort($byMonth);

        return ['groups' => $groups, 'canonical_rows_by_month' => $byMonth, 'summary' => $summary];
    }
}
