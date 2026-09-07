<?php

namespace App\Modules\Finance\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Kol\Application\Contracts\AffiliateCommissionEvidenceReader;
use App\Modules\Logistics\Application\Contracts\CarrierBillingEvidenceReader;
use App\Modules\Products\Application\Contracts\ProductCostEvidenceReader;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class CostMaterializationService
{
    private const STRONG_PROVENANCE = ['actual', 'confirmed_manual', 'provider_import', 'allocated_actual'];

    public function __construct(
        private readonly ProductCostEvidenceReader $products,
        private readonly CarrierBillingEvidenceReader $carrier,
        private readonly AffiliateCommissionEvidenceReader $affiliate,
        private readonly OrderAnalysisService $analysis,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array<string, mixed> */
    public function preview(Model $order): array
    {
        $scopeBlocker = $this->operationalBlocker($order);
        $candidates = [$this->productCandidate($order), $this->carrierCandidate($order), $this->affiliateCandidate($order)];
        if ($scopeBlocker !== null) {
            $candidates = array_map(fn (array $candidate): array => $this->forceBlocked($candidate, $scopeBlocker), $candidates);
        }

        return [
            'order_id' => (int) $order->getKey(),
            'scope_status' => $scopeBlocker === null ? 'operational' : 'blocked',
            'scope_blocker' => $scopeBlocker,
            'candidates' => array_values(array_map(fn (array $candidate): array => $this->annotateDisposition($order, $candidate), $candidates)),
            'unsupported' => ['payment_fee', 'warehouse_cost', 'first_mile_cost', 'overseas_handling_cost', 'customs_tax', 'refund_replacement_loss', 'other_variable_cost'],
        ];
    }

    /** @return array<string, mixed> */
    public function apply(Model $order): array
    {
        return DB::transaction(function () use ($order): array {
            $locked = $order->newQuery()->lockForUpdate()->findOrFail($order->getKey());
            $preview = $this->preview($locked);
            $result = $preview + ['applied' => [], 'noop' => [], 'blocked' => []];

            foreach ($preview['candidates'] as $candidate) {
                $disposition = $candidate['materialization_status'];
                if ($disposition === 'blocked') {
                    $result['blocked'][] = $candidate;
                    continue;
                }
                if ($disposition === 'noop') {
                    $result['noop'][] = $candidate;
                    continue;
                }
                $outcome = $this->writeCandidate($locked, $candidate);
                $result[$outcome['status']][] = $outcome['entry'];
            }

            if ($result['applied'] !== []) {
                $this->analysis->refreshCompleteness($locked, preserveReconciled: false);
                $this->audit->record(
                    AuditActor::system(AuditSource::InternalSystem),
                    'finance.cost_materialization.applied',
                    $locked::class,
                    $locked->getKey(),
                    after: ['applied' => $result['applied'], 'blocked' => $result['blocked']],
                    reason: 'cost_materialization',
                );
            }

            return $result;
        });
    }

    private function productCandidate(Model $order): array
    {
        $rows = $this->products->read($order->getKey());
        $currency = $this->orderCurrency($order);
        if ($rows === []) {
            return $this->blocked('product_cost', 'order_items_missing');
        }

        $total = '0';
        $productIds = [];
        $sourceAt = null;
        foreach ($rows as $row) {
            $quantity = (string) ($row['quantity'] ?? '');
            $purchase = $row['standard_purchase_cost'] ?? null;
            $production = $row['standard_production_cost'] ?? null;
            $packaging = $row['standard_packaging_cost'] ?? null;
            $validQuantity = preg_match('/^[1-9][0-9]*$/D', $quantity) === 1;
            $purchasePath = $purchase !== null && $production === null && $packaging === null;
            $productionPath = $purchase === null && $production !== null;
            if (! $validQuantity || ($row['product_id'] ?? null) === null || strtoupper((string) ($row['cost_currency'] ?? '')) !== $currency || (! $purchasePath && ! $productionPath)) {
                return $this->blocked('product_cost', 'unambiguous_same_currency_reference_required', ['item_id' => $row['item_id'] ?? null]);
            }

            $unit = $purchasePath ? (string) $purchase : DecimalMoney::add((string) $production, (string) ($packaging ?? '0'), 8);
            $total = DecimalMoney::add($total, DecimalMoney::multiply($unit, $quantity, 8), 8);
            $productIds[] = (int) $row['product_id'];
            if (($row['updated_at'] ?? null) !== null && ($sourceAt === null || $row['updated_at'] > $sourceAt)) {
                $sourceAt = $row['updated_at'];
            }
        }
        sort($productIds);

        return $this->candidate(
            'product_cost', DecimalMoney::round($total), $currency, 'estimated_reference', 'estimated',
            'product-reference:v1:order:'.$order->getKey(), $sourceAt, ['product_ids' => $productIds],
        );
    }

    private function carrierCandidate(Model $order): array
    {
        $evidence = $this->carrier->read($order->getKey());
        if ($evidence === null || isset($evidence['blocked'])) {
            return $this->blocked('last_mile_cost', (string) ($evidence['blocked'] ?? 'carrier_evidence_missing'));
        }

        $currency = $this->orderCurrency($order);
        $identity = $evidence['billing_document_number'] ?? $evidence['provider_reference'] ?? $evidence['data_source'] ?? null;
        if ($evidence['actual_cost'] === null || $evidence['currency'] === null || ! is_string($identity) || trim($identity) === '' || strtoupper((string) $evidence['currency']) !== $currency) {
            return $this->blocked('last_mile_cost', 'complete_same_currency_billing_evidence_required');
        }

        return $this->candidate(
            'last_mile_cost', DecimalMoney::round((string) $evidence['actual_cost']), $currency, 'provider_import', 'confirmed',
            'carrier-billing:v1:shipment:'.$evidence['shipment_id'].':billing:'.$evidence['billing_id'],
            $evidence['billed_at'] ?? $evidence['updated_at'] ?? null,
        );
    }

    private function affiliateCandidate(Model $order): array
    {
        $rows = $this->affiliate->read($order->getKey());
        $eligible = array_values(array_filter($rows, static function (array $row): bool {
            return in_array(strtolower((string) ($row['provider_status'] ?? '')), ['unpaid', 'paid'], true)
                && ($row['order_resolution_status'] ?? null) === 'resolved'
                && ($row['reconciliation_status'] ?? null) === 'present';
        }));
        if ($eligible === []) {
            return $this->blocked('kol_commission', $rows === [] ? 'referral_snapshot_missing' : 'no_eligible_referrals');
        }

        $currency = $this->orderCurrency($order);
        $currencies = array_values(array_unique(array_map(fn (array $row): string => strtoupper((string) ($row['currency'] ?? '')), $eligible)));
        if (count($currencies) !== 1 || $currencies[0] !== $currency) {
            return $this->blocked('kol_commission', 'foreign_or_mixed_currency_referrals');
        }

        $total = '0';
        $ids = [];
        $sourceAt = null;
        foreach ($eligible as $row) {
            $total = DecimalMoney::add($total, (string) $row['commission_amount'], 8);
            $ids[] = (int) ($row['referral_id'] ?? $row['id']);
            $candidateSourceAt = $row['last_synced_at'] ?? $row['updated_at'] ?? null;
            if ($candidateSourceAt !== null && ($sourceAt === null || $candidateSourceAt > $sourceAt)) {
                $sourceAt = $candidateSourceAt;
            }
        }
        sort($ids);

        return $this->candidate(
            'kol_commission', DecimalMoney::round($total), $currency, 'provider_import', 'confirmed',
            'affiliatewp-referrals:v1:order:'.$order->getKey(), $sourceAt, ['referral_ids' => $ids],
        );
    }

    /** @return array{status:string,entry:array<string,mixed>} */
    private function writeCandidate(Model $order, array $candidate): array
    {
        $entry = $order->costEntries()->lockForUpdate()->where('cost_type', $candidate['cost_type'])->first();
        $disposition = $this->disposition($candidate, $entry);
        if ($disposition !== 'apply') {
            return ['status' => $disposition, 'entry' => $this->decorateDisposition($candidate, $disposition, $entry)];
        }

        $values = [
            'cost_type' => $candidate['cost_type'],
            'amount' => $candidate['amount'],
            'currency' => $candidate['currency'],
            'provenance' => $candidate['provenance'],
            'status' => $candidate['status'],
            'source_reference' => $candidate['source_reference'],
            'effective_at' => now(),
            'source_at' => $candidate['source_at'],
            'note' => 'ERP-ACCOUNTING-COST-MATERIALIZATION-V1-1-001',
        ];
        if ($entry) {
            $entry->fill($values)->save();
        } else {
            $entry = $order->costEntries()->create($values);
        }

        return ['status' => 'applied', 'entry' => $entry->fresh()->toArray()];
    }

    private function annotateDisposition(Model $order, array $candidate): array
    {
        $entry = $order->costEntries()->where('cost_type', $candidate['cost_type'])->first();
        if (($candidate['status'] ?? null) === 'blocked') {
            $candidate['materialization_status'] = 'blocked';
            if ($entry && $this->isMaterializerOwned($entry)) {
                $candidate['stale_source'] = true;
                $candidate['stale_entry'] = $entry->toArray();
            }
            return $candidate;
        }

        $disposition = $this->disposition($candidate, $entry);
        return $this->decorateDisposition($candidate, $disposition, $entry);
    }

    private function disposition(array $candidate, ?Model $entry): string
    {
        if ($entry === null) {
            return 'apply';
        }
        if ($candidate['provenance'] === 'estimated_reference' && in_array((string) $entry->provenance, self::STRONG_PROVENANCE, true)) {
            return 'blocked';
        }
        if ($candidate['provenance'] !== 'estimated_reference' && in_array((string) $entry->provenance, ['actual', 'confirmed_manual', 'allocated_actual'], true)) {
            return 'blocked';
        }
        if ((string) $entry->provenance === (string) $candidate['provenance'] && (string) $entry->source_reference !== (string) $candidate['source_reference']) {
            return 'blocked';
        }
        if ((string) $entry->source_reference === (string) $candidate['source_reference']
            && (string) $entry->amount === (string) $candidate['amount']
            && strtoupper((string) $entry->currency) === strtoupper((string) $candidate['currency'])
            && (string) $entry->provenance === (string) $candidate['provenance']
            && (string) $entry->status === (string) $candidate['status']) {
            return 'noop';
        }

        return 'apply';
    }

    private function decorateDisposition(array $candidate, string $disposition, ?Model $entry): array
    {
        $candidate['materialization_status'] = $disposition;
        if ($disposition === 'blocked' && $entry !== null) {
            $candidate['blocker'] = 'stronger_or_different_existing_fact';
            $candidate['existing_entry'] = $entry->toArray();
        }
        if ($disposition === 'noop' && $entry !== null) {
            $candidate['existing_entry_id'] = (int) $entry->getKey();
        }
        return $candidate;
    }

    private function operationalBlocker(Model $order): ?string
    {
        $kind = $order->order_kind;
        $kind = $kind instanceof BackedEnum ? $kind->value : (string) $kind;
        if ($kind !== 'customer') {
            return 'order_kind_not_customer';
        }
        if ($order->externalReferences()->where('provider', 'historical_logistics')->exists()) {
            return 'historical_logistics_excluded';
        }
        return null;
    }

    private function forceBlocked(array $candidate, string $blocker): array
    {
        return ['cost_type' => $candidate['cost_type'], 'status' => 'blocked', 'blocker' => $blocker];
    }

    private function isMaterializerOwned(Model $entry): bool
    {
        $source = (string) $entry->source_reference;
        return str_starts_with($source, 'product-reference:v1:')
            || str_starts_with($source, 'carrier-billing:v1:')
            || str_starts_with($source, 'affiliatewp-referrals:v1:');
    }

    private function orderCurrency(Model $order): string
    {
        return strtoupper((string) (($order->financials()->first()?->currency) ?? $order->currency ?? 'USD'));
    }

    private function candidate(string $type, string $amount, string $currency, string $provenance, string $status, string $source, mixed $sourceAt, array $extra = []): array
    {
        return ['cost_type' => $type, 'amount' => $amount, 'currency' => strtoupper($currency), 'provenance' => $provenance, 'status' => $status, 'source_reference' => $source, 'source_at' => $sourceAt] + $extra;
    }

    private function blocked(string $type, string $blocker, array $extra = []): array
    {
        return ['cost_type' => $type, 'status' => 'blocked', 'blocker' => $blocker] + $extra;
    }
}
