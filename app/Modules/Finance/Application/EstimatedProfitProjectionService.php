<?php

namespace App\Modules\Finance\Application;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Read-only P8.2 estimated profit projection.
 * Computes an estimated profit from confirmed + estimated cost facts.
 * Optional absent costs (refund_replacement_loss, other_variable_cost) treated as provisional zero.
 * Never persists anything — projection only.
 */
final class EstimatedProfitProjectionService
{
    private const REQUIRED_COST_TYPES = [
        'product_cost', 'first_mile_cost', 'overseas_handling_cost',
        'last_mile_cost', 'payment_fee', 'customs_tax',
    ];

    private const OPTIONAL_COST_TYPES = [
        'warehouse_cost', 'kol_commission',
        'refund_replacement_loss', 'other_variable_cost',
    ];

    private const STRONG_PROVENANCE = ['actual', 'confirmed_manual', 'provider_import', 'allocated_actual'];

    public function __construct(
        private readonly OrderMaturityService $maturity,
        private readonly CostMaterializationService $materializer,
    ) {}

    /**
     * @return array{
     *   canonical_estimated_ready: bool,
     *   canonical_estimated_profit: string|null,
     *   canonical_estimated_margin: string|null,
     *   estimated_cost_count: int,
     *   confirmed_cost_count: int,
     *   missing_required_cost_count: int,
     *   cost_completion_percent: int,
     *   age_days: int|null,
     *   maturity_state: string|null,
     *   reconciliation_due: bool,
     *   unresolved_estimate_count: int,
     * }
     */
    public function project(Model $order): array
    {
        $maturityData = $this->maturity->forOrder($order);
        $order->loadMissing(['financials', 'costEntries']);

        /** @var Collection<string, Model> $entries */
        $entries = $order->costEntries->keyBy('cost_type');
        $financials = $order->financials;
        $currency = strtoupper((string) ($financials?->currency ?? $order->currency ?? 'USD'));

        // Get candidate projections from materializer for missing/estimated costs
        $preview = $this->materializer->previewExplanation($order);
        $candidates = collect($preview['candidates'])->keyBy('cost_type');

        $estimatedCostCount = 0;
        $confirmedCostCount = 0;
        $missingRequiredCount = 0;
        $totalCostCents = 0;
        $currencyCompatible = true;

        foreach ([...self::REQUIRED_COST_TYPES, ...self::OPTIONAL_COST_TYPES] as $type) {
            $entry = $entries->get($type);
            $candidate = $candidates->get($type);
            $isOptional = in_array($type, self::OPTIONAL_COST_TYPES, true);

            [$state, $amountCents] = $this->resolveLineCents($type, $entry, $candidate, $currency, $isOptional);

            match ($state) {
                'confirmed' => $confirmedCostCount++,
                'estimated' => $estimatedCostCount++,
                'provisional_zero' => null,
                'missing' => $isOptional ? null : $missingRequiredCount++,
                default => ($isOptional ? null : $missingRequiredCount++),
            };

            if ($amountCents !== null) {
                $totalCostCents += $amountCents;
            } elseif (! $isOptional && $state === 'missing') {
                $currencyCompatible = false;
            }
        }

        $allCostTypes = count(self::REQUIRED_COST_TYPES) + count(self::OPTIONAL_COST_TYPES);
        $postedCount = $confirmedCostCount + $estimatedCostCount;
        $costCompletionPercent = $allCostTypes > 0
            ? (int) round($postedCount / $allCostTypes * 100)
            : 0;

        [$netRevenueCents, $revenueReady] = $this->netRevenueCents($financials);

        $estimatedReady = $missingRequiredCount === 0 && $revenueReady && $currencyCompatible;

        $estimatedProfit = null;
        $estimatedMargin = null;
        if ($estimatedReady && $netRevenueCents !== null) {
            $profitCents = $netRevenueCents - $totalCostCents;
            $estimatedProfit = number_format($profitCents / 100, 2, '.', '');
            if ($netRevenueCents !== 0) {
                $estimatedMargin = bcdiv((string) $profitCents, (string) $netRevenueCents, 4);
            }
        }

        $unresolvedEstimateCount = $estimatedCostCount + ($entries->count() - $confirmedCostCount - $estimatedCostCount > 0 ? 0 : 0);

        return [
            'canonical_estimated_ready'   => $estimatedReady,
            'canonical_estimated_profit'  => $estimatedProfit,
            'canonical_estimated_margin'  => $estimatedMargin,
            'estimated_cost_count'        => $estimatedCostCount,
            'confirmed_cost_count'        => $confirmedCostCount,
            'missing_required_cost_count' => $missingRequiredCount,
            'cost_completion_percent'     => $costCompletionPercent,
            'age_days'                    => $maturityData['age_days'],
            'maturity_state'              => $maturityData['maturity_state'],
            'reconciliation_due'          => $maturityData['reconciliation_due'],
            'unresolved_estimate_count'   => $estimatedCostCount,
        ];
    }

    /** @return array{string, int|null} [state, cents] */
    private function resolveLineCents(string $type, ?Model $entry, ?array $candidate, string $currency, bool $isOptional): array
    {
        if ($entry !== null) {
            $provenance = (string) ($entry->provenance ?? '');
            $status = (string) ($entry->status ?? '');
            $entryCurrency = strtoupper((string) $entry->currency);
            if ($entryCurrency !== $currency) {
                return ['missing', null];
            }
            if (in_array($provenance, self::STRONG_PROVENANCE, true) && in_array($status, ['confirmed', 'adjusted'], true)) {
                return ['confirmed', $this->cents($entry->amount)];
            }
            if ($provenance === 'estimated_reference' || $status === 'estimated') {
                return ['estimated', $this->cents($entry->amount)];
            }
            return ['review', null];
        }

        if ($candidate !== null && ($candidate['status'] ?? null) !== 'blocked' && isset($candidate['amount'], $candidate['currency'])) {
            $candidateCurrency = strtoupper((string) $candidate['currency']);
            if ($candidateCurrency === $currency) {
                $state = ($candidate['provenance'] ?? null) === 'estimated_reference' ? 'estimated' : 'confirmed';
                return [$state, $this->cents($candidate['amount'])];
            }
        }

        if ($isOptional) {
            return ['provisional_zero', 0];
        }

        return ['missing', null];
    }

    /** @return array{int|null, bool} [net_revenue_cents, ready] */
    private function netRevenueCents(?Model $financials): array
    {
        if ($financials === null) {
            return [null, false];
        }

        if ($financials->merchandise_list_amount !== null && $financials->refund_amount !== null && $financials->shipping_revenue !== null && $financials->discount_amount !== null) {
            $cents = $this->cents($financials->merchandise_list_amount)
                - $this->cents($financials->discount_amount)
                + $this->cents($financials->shipping_revenue)
                - $this->cents($financials->refund_amount);
            return [$cents, true];
        }

        if ($financials->amount_paid !== null && $financials->refund_amount !== null) {
            $cents = $this->cents($financials->amount_paid) - $this->cents($financials->refund_amount);
            return [$cents, true];
        }

        return [null, false];
    }

    private function cents(mixed $amount): int
    {
        $value = trim((string) $amount);
        if (! preg_match('/^(?<sign>-)?(?<whole>\d+)(?:\.(?<fraction>\d{1,2}))?$/D', $value, $matches)) {
            return 0;
        }
        $cents = ((int) $matches['whole'] * 100) + (int) str_pad($matches['fraction'] ?? '', 2, '0');
        return ($matches['sign'] ?? '') === '-' ? -$cents : $cents;
    }
}
