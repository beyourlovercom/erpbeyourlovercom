<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

/** B4 arithmetic only; consumes complete approved A4 comparisons on explicitly
 * verified distinct historical cohorts. Drafts and unknown parcel counts never rank.
 */
final class PackagingOptimizationMonthlyProjection
{
    /** Sort only fully covered verified projections; never mix partial samples or drafts. */
    public function rank(array $products): array
    {
        $eligible = array_filter($products, fn ($p) => ($p['eligible_for_ranking'] ?? false) === true
            && ($p['status'] ?? null) === 'complete_sample' && is_numeric($p['monthly_logistics_saving_cny'] ?? null)
            && is_finite((float)$p['monthly_logistics_saving_cny']));
        uasort($eligible, fn ($a,$b) => $b['monthly_logistics_saving_cny'] <=> $a['monthly_logistics_saving_cny']);
        return ['ranking_basis'=>'logistics_only_material_cost_unknown','ranked'=>$eligible,'excluded'=>array_diff_key($products,$eligible)];
    }

    public function summarize(array $cohorts, int $days, int $observedShipments): array
    {
        if ($days < 1 || $days > 365 || $observedShipments < 0) throw new \InvalidArgumentException('Invalid cohort period');
        $seen = [];
        $covered = 0;
        $saving = 0.0;
        foreach ($cohorts as $c) {
            $key = $c['cohort_key'] ?? null;
            $count = $c['shipment_count'] ?? null;
            if (! is_string($key) || $key === '' || isset($seen[$key]) || ! is_int($count) || $count < 1) throw new \InvalidArgumentException('Invalid or duplicate cohort');
            $seen[$key] = true;
            $comparison = $c['comparison'] ?? [];
            $delta = $comparison['logistics_saving_cny'] ?? null;
            if (($c['parcel_identity_verified'] ?? false) !== true || ($c['candidate_protection_verified'] ?? false) !== true
                || ($c['candidate_measurement_record_id'] ?? 0) <= 0 || ($comparison['status'] ?? null) !== 'ready'
                || ($comparison['eligible_for_ranking'] ?? false) !== true || ! is_numeric($delta) || ! is_finite((float)$delta)) continue;
            $covered += $count;
            $saving += (float)$delta * $count;
        }
        if ($covered > $observedShipments) throw new \InvalidArgumentException('Cohort count exceeds observed shipments');
        return ['observed_shipments'=>$observedShipments,'covered_shipments'=>$covered,'excluded_shipments'=>$observedShipments-$covered,
            'period_days'=>$days,'monthly_equivalent_days'=>30,'monthly_logistics_saving_cny'=>$covered ? round($saving*30/$days,2) : null,
            'monthly_net_saving_cny'=>null,'status'=>$covered === 0 ? 'unavailable' : ($covered === $observedShipments ? 'complete_sample' : 'partial_sample'),
            'eligible_for_ranking'=>$covered > 0 && $covered === $observedShipments,'estimate_only'=>true];
    }
}
