<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\Infrastructure\Models\PackagingMeasurementRecord;
use App\Modules\Products\Application\PackagingPresetCatalog;
use Carbon\CarbonImmutable;

/**
 * B4 orchestration for explicitly verified single-item cohorts.
 * Internal read-only: verification must be supplied by a trusted business reviewer,
 * not by an untrusted HTTP request. No automatic alias or eligibility inference.
 */
final class PackagingOptimizationHistoricalComparison
{
    public function __construct(
        private readonly PackagingOptimizationRouteSamples $samples,
        private readonly PackagingOptimizationQuoteComparison $quotes,
        private readonly PackagingOptimizationMonthlyProjection $projection,
        private readonly PackagingPresetCatalog $presets,
    ) {}

    public function project(int $productId, int $candidateRecordId, CarbonImmutable $end, string $quoteDate, array $verified, int $days=90): array
    {
        $sample = $this->samples->forProduct($productId,$end,$days);
        $record = PackagingMeasurementRecord::query()->whereKey($candidateRecordId)->where('product_id',$productId)->where('status','approved')->first();
        $method = $record?->packaging_method_key;
        $eligibleCandidate = $record && in_array($method,$this->presets->keys(),true)
            && (float) $record->packaged_weight_kg > 0 && (float) $record->packaging_length_cm > 0
            && (float) $record->packaging_width_cm > 0 && (float) $record->packaging_height_cm > 0
            && (! $record->photo_requirement_reason || ($record->media()->where('kind','measurement')->exists()
                && $record->media()->where('kind','weight')->exists()));
        $rows = [];
        foreach ($sample['cohorts'] as $key=>$cohort) {
            $proof = $verified[$key] ?? [];
            $entry = ['cohort_key'=>$key,'shipment_count'=>$cohort['shipment_count'],'parcel_identity_verified'=>false,
                'candidate_protection_verified'=>false,'candidate_measurement_record_id'=>$candidateRecordId,'comparison'=>[]];
            // Exact historical route match only. A owns service aliases and fee applicability.
            if ($eligibleCandidate && ($proof['parcel_identity_verified'] ?? false) === true
                && ($proof['candidate_protection_verified'] ?? false) === true && ($proof['eligibility_confirmed'] ?? false) === true
                && is_string($proof['evidence_reference'] ?? null) && trim($proof['evidence_reference']) !== ''
                && is_int($proof['version_id'] ?? null) && in_array($proof['handling_policy'] ?? null,['supplier','internal'],true)
                && is_string($cohort['route']['postal_code'] ?? null)) {
                $route = [...$cohort['route'],'version_id'=>$proof['version_id'],'as_of'=>$quoteDate,'handling_policy'=>$proof['handling_policy']];
                $common = ['items'=>[$productId=>1],'weight_basis'=>'measured_packaged','eligibility'=>'confirmed'];
                $old = [...$common,...$cohort['snapshot']];
                $new = [...$common,'weight_kg'=>$record->packaged_weight_kg,
                    'dimensions_cm'=>[$record->packaging_length_cm,$record->packaging_width_cm,$record->packaging_height_cm]];
                $comparison = $this->quotes->compare($route,[$old],[$method=>[$new]]);
                $entry = [...$entry,'parcel_identity_verified'=>true,'candidate_protection_verified'=>true,
                    'evidence_reference'=>$proof['evidence_reference'],'historical_measurement_record_id'=>$cohort['historical_measurement_record_id'],
                    'version_id'=>$proof['version_id'],'baseline_quote'=>$comparison['baseline'],'comparison'=>$comparison['candidates'][$method]];
            }
            $rows[]=$entry;
        }
        return ['product_id'=>$productId,'candidate_record_id'=>$candidateRecordId,'candidate_evidence_complete'=>(bool)$eligibleCandidate,
            'start'=>$sample['start'],'end_exclusive'=>$sample['end_exclusive'],'quote_date'=>$quoteDate,'cohorts'=>$rows,
            'projection'=>$this->projection->summarize($rows,$days,$sample['observed_shipments'])];
    }
}
