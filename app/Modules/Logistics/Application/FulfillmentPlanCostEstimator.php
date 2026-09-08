<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Finance\Application\FxReferenceSettingsService;
use App\Modules\Inventory\Application\ProductPackagingStandardReader;
use App\Modules\Products\Application\ProductShippingWeightStandardReader;

final class FulfillmentPlanCostEstimator
{
    public function __construct(
        private readonly ProductPackagingStandardReader $packaging,
        private readonly ProductShippingWeightStandardReader $shippingWeights,
        private readonly FxReferenceSettingsService $fx,
        private readonly ChinaFactoryRoutingCostCalculator $china,
        private readonly GermanyDhlRoutingCostCalculator $dhl,
    ) {}

    /** @param array<int,int> $productIds @return array<string,mixed> */
    public function prepare(array $productIds): array
    {
        return [
            'packaging_standards' => $this->packaging->forProductIds($productIds),
            'shipping_weight_standards' => $this->shippingWeights->forProductIds($productIds),
        ];
    }

    /** @param list<array<string,mixed>> $segments @param array<string,mixed> $destinationContext @param array<string,mixed> $context */
    public function estimate(string $country, array $segments, array $destinationContext = [], array $context = []): array
    {
        $productIds = collect($segments)
            ->flatMap(fn (array $segment) => collect($segment['items'] ?? [])->pluck('product_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()->values()->all();
        $packagingStandards = $context['packaging_standards'] ?? $this->packaging->forProductIds($productIds);
        $shippingWeightStandards = $context['shipping_weight_standards'] ?? $this->shippingWeights->forProductIds($productIds);

        $allComponents = [];
        $segmentEvidence = [];
        foreach ($segments as $segment) {
            $sourceType = (string) ($segment['source_type'] ?? '');
            $sourceCode = strtoupper((string) ($segment['source_code'] ?? ''));
            $carrier = trim((string) ($segment['carrier'] ?? ''));

            if ($sourceType === 'factory' && $carrier === '4PX') {
                $measurement = $this->shippingWeightForSegment($segment, $shippingWeightStandards);
                if ($measurement['status'] !== 'ready') return $measurement;
                $routing = $this->china->calculate(
                    $country,
                    $measurement['actual_weight_kg'],
                    $segment['items'] ?? [],
                    $destinationContext,
                );
            } else {
                $measurement = $this->packagingForSegment($segment, $packagingStandards);
                if ($measurement['status'] !== 'ready') return $measurement;

                if ($sourceType === 'factory') {
                    $routing = [
                        'status' => 'missing_evidence', 'amount' => null, 'currency' => null, 'source' => null,
                        'reason_code' => 'factory_carrier_rate_unavailable',
                        'reason' => "No frozen automatic routing rate for factory carrier {$carrier}",
                    ];
                } else {
                    $routing = $sourceCode === 'DE'
                        ? $this->dhl->calculate($country, $measurement['actual_weight_kg'])
                        : [
                            'status' => 'missing_evidence', 'amount' => null, 'currency' => null, 'source' => null,
                            'reason_code' => 'routing_rate_unavailable',
                            'reason' => 'No frozen automatic routing rate for source',
                        ];
                }
            }

            if (($routing['status'] ?? null) !== 'ready') return [...$routing, 'packaging' => $measurement];
            $allComponents = [...$allComponents, ...$routing['components']];
            $segmentEvidence[] = ['source_code' => $sourceCode, 'packaging' => $measurement, 'routing' => $routing];
        }

        $fxResult = $this->fx->convertToCny(array_map(
            fn (array $component): array => ['currency' => $component['currency'], 'revenue' => $component['amount']],
            $allComponents,
        ));
        if (! ($fxResult['available'] ?? false)) {
            return [
                'status' => 'missing_evidence', 'amount' => null, 'currency' => null, 'source' => null,
                'reason_code' => $fxResult['reason'] ?? 'fx_unavailable',
                'reason' => 'ERP Settings FX conversion unavailable',
                'components' => $allComponents,
                'segments' => $segmentEvidence,
            ];
        }

        $provenances = collect($segmentEvidence)->pluck('packaging.provenance')->filter()->unique()->values();
        $provenance = $provenances->count() === 1
            ? (string) $provenances->first()
            : 'mixed-measurement-evidence';

        return [
            'status' => 'ready',
            'amount' => round((float) $fxResult['amount_cny'], 4),
            'currency' => 'CNY',
            'source' => 'automatic-rate-engine',
            'components' => $allComponents,
            'segments' => $segmentEvidence,
            'fx' => [
                'source' => $fxResult['source'] ?? null,
                'reference_date' => $fxResult['reference_date'] ?? null,
                'stale' => (bool) ($fxResult['stale'] ?? false),
                'conversions' => $fxResult['conversions'] ?? [],
            ],
            'provenance' => $provenance,
            'possible_surcharges' => collect($segmentEvidence)->flatMap(fn (array $row) => $row['routing']['possible_surcharges'] ?? [])->unique()->values()->all(),
        ];
    }

    private function shippingWeightForSegment(array $segment, array $standards): array
    {
        $actual = 0.0;
        $evidenceIds = [];
        foreach ($segment['items'] ?? [] as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);
            $standard = $standards[$productId] ?? null;
            if ($quantity <= 0 || ! is_array($standard) || ! is_numeric($standard['shipping_weight_kg'] ?? null) || (float) $standard['shipping_weight_kg'] <= 0) {
                return [
                    'status' => 'missing_evidence', 'amount' => null, 'currency' => null, 'source' => null,
                    'reason_code' => 'missing_shipping_reference_weight',
                    'reason' => 'Adopted shipping reference weight is required for actual-weight factory routing',
                    'product_id' => $productId,
                ];
            }
            $actual += (float) $standard['shipping_weight_kg'] * $quantity;
            if (isset($standard['evidence_id'])) $evidenceIds[] = (int) $standard['evidence_id'];
        }

        return [
            'status' => 'ready',
            'actual_weight_kg' => round($actual, 6),
            'volume_cm3' => null,
            'provenance' => 'approved-shipping-reference-weight',
            'evidence_ids' => array_values(array_unique($evidenceIds)),
        ];
    }

    private function packagingForSegment(array $segment, array $standards): array
    {
        $actual = 0.0;
        $volume = 0.0;
        foreach ($segment['items'] ?? [] as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);
            $standard = $standards[$productId] ?? null;
            if ($quantity <= 0 || ! is_array($standard) || ! $this->hasPackaging($standard)) {
                return [
                    'status' => 'missing_evidence', 'amount' => null, 'currency' => null, 'source' => null,
                    'reason_code' => 'missing_packaging_standard',
                    'reason' => 'Approved product packaging standard is required',
                ];
            }
            $actual += (float) $standard['packaged_weight_kg'] * $quantity;
            $volume += (float) $standard['packaging_length_cm'] * (float) $standard['packaging_width_cm'] * (float) $standard['packaging_height_cm'] * $quantity;
        }

        return [
            'status' => 'ready',
            'actual_weight_kg' => round($actual, 6),
            'volume_cm3' => round($volume, 6),
            'provenance' => 'approved-packaging-standard',
        ];
    }

    private function hasPackaging(array $standard): bool
    {
        foreach (['packaged_weight_kg', 'packaging_length_cm', 'packaging_width_cm', 'packaging_height_cm'] as $key) {
            if (! is_numeric($standard[$key] ?? null) || (float) $standard[$key] <= 0) return false;
        }
        return true;
    }
}
