<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Finance\Application\EcbFxService;
use App\Modules\Inventory\Application\ProductPackagingStandardReader;

final class FulfillmentPlanCostEstimator
{
    public function __construct(
        private readonly ProductPackagingStandardReader $packaging,
        private readonly EcbFxService $fx,
        private readonly ChinaFactoryRoutingCostCalculator $china,
        private readonly GermanyDhlRoutingCostCalculator $dhl,
    ) {}

    /** @param list<array<string,mixed>> $segments @param array<string,mixed> $destinationContext */
    public function estimate(string $country, array $segments, array $destinationContext = []): array
    {
        $productIds = collect($segments)->flatMap(fn (array $segment) => collect($segment['items'] ?? [])->pluck('product_id'))->map(fn ($id) => (int) $id)->unique()->values()->all();
        $standards = $this->packaging->forProductIds($productIds);
        $allComponents = [];
        $segmentEvidence = [];
        foreach ($segments as $segment) {
            $packaging = $this->packagingForSegment($segment, $standards);
            if ($packaging['status'] !== 'ready') return $packaging;
            $sourceType = (string) ($segment['source_type'] ?? '');
            $sourceCode = strtoupper((string) ($segment['source_code'] ?? ''));
            $routing = $sourceType === 'factory'
                ? $this->china->calculate($country, $packaging['actual_weight_kg'], $packaging['volume_cm3'], $segment['items'] ?? [], $destinationContext)
                : ($sourceCode === 'DE' ? $this->dhl->calculate($country, $packaging['actual_weight_kg']) : ['status' => 'missing_evidence', 'amount' => null, 'currency' => null, 'source' => null, 'reason_code' => 'routing_rate_unavailable', 'reason' => 'No frozen automatic routing rate for source']);
            if (($routing['status'] ?? null) !== 'ready') return [...$routing, 'packaging' => $packaging];
            $allComponents = [...$allComponents, ...$routing['components']];
            $segmentEvidence[] = ['source_code' => $sourceCode, 'packaging' => $packaging, 'routing' => $routing];
        }

        $fxResult = $this->fx->convertToCny(array_map(fn (array $component): array => ['currency' => $component['currency'], 'revenue' => $component['amount']], $allComponents));
        if (! ($fxResult['available'] ?? false)) return ['status' => 'missing_evidence', 'amount' => null, 'currency' => null, 'source' => null, 'reason_code' => $fxResult['reason'] ?? 'fx_unavailable', 'reason' => 'ECB reference conversion unavailable', 'components' => $allComponents, 'segments' => $segmentEvidence];

        return [
            'status' => 'ready', 'amount' => round((float) $fxResult['amount_cny'], 4), 'currency' => 'CNY', 'source' => 'automatic-rate-engine',
            'components' => $allComponents, 'segments' => $segmentEvidence,
            'fx' => ['source' => $fxResult['source'] ?? null, 'reference_date' => $fxResult['reference_date'] ?? null, 'stale' => (bool) ($fxResult['stale'] ?? false), 'conversions' => $fxResult['conversions'] ?? []],
            'provenance' => count($segments) > 1 || collect($segments)->sum(fn (array $segment): int => count($segment['items'] ?? [])) > 1 ? 'estimated-consolidated-packaging' : 'approved-packaging-standard',
            'possible_surcharges' => collect($segmentEvidence)->flatMap(fn (array $row) => $row['routing']['possible_surcharges'] ?? [])->unique()->values()->all(),
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
            if ($quantity <= 0 || ! is_array($standard) || ! $this->hasPackaging($standard)) return ['status' => 'missing_evidence', 'amount' => null, 'currency' => null, 'source' => null, 'reason_code' => 'missing_packaging_standard', 'reason' => 'Approved product packaging standard is required'];
            $actual += (float) $standard['packaged_weight_kg'] * $quantity;
            $volume += (float) $standard['packaging_length_cm'] * (float) $standard['packaging_width_cm'] * (float) $standard['packaging_height_cm'] * $quantity;
        }
        return ['status' => 'ready', 'actual_weight_kg' => round($actual, 6), 'volume_cm3' => round($volume, 6), 'provenance' => 'approved-packaging-standard'];
    }

    private function hasPackaging(array $standard): bool
    {
        foreach (['packaged_weight_kg', 'packaging_length_cm', 'packaging_width_cm', 'packaging_height_cm'] as $key) if (! is_numeric($standard[$key] ?? null) || (float) $standard[$key] <= 0) return false;
        return true;
    }
}
