<?php

namespace App\Modules\Finance\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Kol\Application\Contracts\AffiliateCommissionEvidenceReader;
use App\Modules\Logistics\Application\Contracts\CarrierBillingEvidenceReader;
use App\Modules\Logistics\Application\Contracts\FulfillmentOriginEvidenceReader;
use App\Modules\Logistics\Application\Contracts\LastMileEstimateEvidenceReader;
use App\Modules\Products\Application\Contracts\ProductCostEvidenceReader;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class CostMaterializationService
{
    private const STRONG_PROVENANCE = ['actual', 'confirmed_manual', 'provider_import', 'allocated_actual'];
    private const OVERSEAS_WAREHOUSE_SLUGS = ['us-la', 'us-ny', 'de', 'ca', 'us-3sm'];
    private const CHINA_DIRECT_WAREHOUSE_SLUGS = ['factory', '007'];
    private const PAYMENT_FEE_RATE = '0.03500000';

    public function __construct(
        private readonly ProductCostEvidenceReader $products,
        private readonly CarrierBillingEvidenceReader $carrier,
        private readonly LastMileEstimateEvidenceReader $lastMileEstimates,
        private readonly FulfillmentOriginEvidenceReader $fulfillmentOrigin,
        private readonly AffiliateCommissionEvidenceReader $affiliate,
        private readonly AccountingFxSnapshotBridgeService $fxBridge,
        private readonly OrderAnalysisService $analysis,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array<string, mixed> */
    public function preview(Model $order): array
    {
        $scopeBlocker = $this->operationalBlocker($order);
        $candidates = [
            $this->productCandidate($order),
            $this->carrierCandidate($order),
            $this->affiliateCandidate($order),
            $this->firstMileCandidate($order),
            $this->customsCandidate($order),
            $this->paymentFeeCandidate($order),
        ];
        if ($scopeBlocker !== null) {
            $candidates = array_map(fn (array $candidate): array => $this->forceBlocked($candidate, $scopeBlocker), $candidates);
        }

        return [
            'order_id' => (int) $order->getKey(),
            'scope_status' => $scopeBlocker === null ? 'operational' : 'blocked',
            'scope_blocker' => $scopeBlocker,
            'candidates' => array_values(array_map(fn (array $candidate): array => $this->annotateDisposition($order, $candidate), $candidates)),
            'unsupported' => ['warehouse_cost', 'overseas_handling_cost', 'refund_replacement_loss', 'other_variable_cost'],
        ];
    }

    /** @return array<string, mixed> */
    public function previewPaymentFeeGate(Model $order): array
    {
        $scopeBlocker = $this->operationalBlocker($order);
        $candidate = $this->paymentFeeCandidate($order);
        if ($scopeBlocker !== null) {
            $candidate = $this->forceBlocked($candidate, $scopeBlocker);
        }

        return [
            'order_id' => (int) $order->getKey(),
            'scope_status' => $scopeBlocker === null ? 'operational' : 'blocked',
            'scope_blocker' => $scopeBlocker,
            'candidates' => [$this->annotateDisposition($order, $candidate)],
        ];
    }

    /** @return array<string, mixed> */
    public function applyPaymentFeeGate(Model $order): array
    {
        return DB::transaction(function () use ($order): array {
            $locked = $order->newQuery()->lockForUpdate()->findOrFail($order->getKey());
            $preview = $this->previewPaymentFeeGate($locked);
            $result = $preview + ['applied' => [], 'noop' => [], 'blocked' => []];
            $candidate = $preview['candidates'][0];
            $disposition = $candidate['materialization_status'];
            if ($disposition === 'blocked') {
                $result['blocked'][] = $candidate;
            } elseif ($disposition === 'noop') {
                $result['noop'][] = $candidate;
            } else {
                $outcome = $this->writeCandidate($locked, $candidate);
                $result[$outcome['status']][] = $outcome['entry'];
            }

            if ($result['applied'] !== []) {
                $this->analysis->refreshCompleteness($locked, preserveReconciled: false);
                $this->audit->record(
                    AuditActor::system(AuditSource::InternalSystem),
                    'finance.payment_fee_materialization.applied',
                    $locked::class,
                    $locked->getKey(),
                    after: ['applied' => $result['applied'], 'blocked' => $result['blocked']],
                    reason: 'payment_fee_materialization',
                );
            }

            return $result;
        });
    }

    /** @return array<string, mixed> */
    public function previewCarrierBillingGate(Model $order): array
    {
        $scopeBlocker = $this->operationalBlocker($order);
        $candidate = $this->carrierCandidate($order);
        if ($scopeBlocker !== null) $candidate = $this->forceBlocked($candidate, $scopeBlocker);

        return [
            'order_id' => (int) $order->getKey(),
            'scope_status' => $scopeBlocker === null ? 'operational' : 'blocked',
            'scope_blocker' => $scopeBlocker,
            'candidates' => [$this->annotateDisposition($order, $candidate)],
        ];
    }

    /** @return array<string, mixed> */
    public function applyCarrierBillingGate(Model $order): array
    {
        return DB::transaction(function () use ($order): array {
            $locked = $order->newQuery()->lockForUpdate()->findOrFail($order->getKey());
            $preview = $this->previewCarrierBillingGate($locked);
            $result = $preview + ['applied' => [], 'noop' => [], 'blocked' => []];
            $candidate = $preview['candidates'][0];
            $disposition = $candidate['materialization_status'];
            if ($disposition === 'blocked') $result['blocked'][] = $candidate;
            elseif ($disposition === 'noop') $result['noop'][] = $candidate;
            else {
                $outcome = $this->writeCandidate($locked, $candidate);
                $result[$outcome['status']][] = $outcome['entry'];
            }

            if ($result['applied'] !== []) {
                $this->analysis->refreshCompleteness($locked, preserveReconciled: false);
                $this->audit->record(
                    AuditActor::system(AuditSource::InternalSystem),
                    'finance.actual_last_mile_materialization.applied',
                    $locked::class, $locked->getKey(),
                    after: ['applied' => $result['applied'], 'blocked' => $result['blocked']],
                    reason: 'actual_last_mile_materialization',
                );
            }
            return $result;
        });
    }

    /** @return array<string, mixed> */
    public function previewLastMileEstimateGate(Model $order): array
    {
        $scopeBlocker = $this->operationalBlocker($order);
        $candidate = $this->lastMileEstimateCandidate($order);
        if ($scopeBlocker !== null) $candidate = $this->forceBlocked($candidate, $scopeBlocker);

        return [
            'order_id' => (int) $order->getKey(),
            'scope_status' => $scopeBlocker === null ? 'operational' : 'blocked',
            'scope_blocker' => $scopeBlocker,
            'candidates' => [$this->annotateDisposition($order, $candidate)],
        ];
    }

    /** @return array<string, mixed> */
    public function applyLastMileEstimateGate(Model $order): array
    {
        return DB::transaction(function () use ($order): array {
            $locked = $order->newQuery()->lockForUpdate()->findOrFail($order->getKey());
            $preview = $this->previewLastMileEstimateGate($locked);
            $result = $preview + ['applied' => [], 'noop' => [], 'blocked' => []];
            $candidate = $preview['candidates'][0];
            $disposition = $candidate['materialization_status'];
            if ($disposition === 'blocked') $result['blocked'][] = $candidate;
            elseif ($disposition === 'noop') $result['noop'][] = $candidate;
            else {
                $outcome = $this->writeCandidate($locked, $candidate);
                $result[$outcome['status']][] = $outcome['entry'];
            }

            if ($result['applied'] !== []) {
                $this->analysis->refreshCompleteness($locked, preserveReconciled: false);
                $this->audit->record(
                    AuditActor::system(AuditSource::InternalSystem),
                    'finance.last_mile_estimate_materialization.applied',
                    $locked::class, $locked->getKey(),
                    after: ['applied' => $result['applied'], 'blocked' => $result['blocked']],
                    reason: 'last_mile_estimate_materialization',
                );
            }
            return $result;
        });
    }

    /** @return array<string, mixed> */
    public function previewFixedRules(Model $order): array
    {
        $scopeBlocker = $this->operationalBlocker($order);
        $candidates = [$this->firstMileCandidate($order), $this->customsCandidate($order)];
        if ($scopeBlocker !== null) {
            $candidates = array_map(fn (array $candidate): array => $this->forceBlocked($candidate, $scopeBlocker), $candidates);
        }

        return [
            'order_id' => (int) $order->getKey(),
            'scope_status' => $scopeBlocker === null ? 'operational' : 'blocked',
            'scope_blocker' => $scopeBlocker,
            'candidates' => array_values(array_map(fn (array $candidate): array => $this->annotateDisposition($order, $candidate), $candidates)),
        ];
    }

    /** @return array<string, mixed> */
    public function previewProductCostGate(Model $order): array
    {
        $scopeBlocker = $this->operationalBlocker($order);
        $product = $this->productCandidate($order);
        if (($product['status'] ?? null) !== 'blocked'
            && (($product['provenance'] ?? null) !== 'confirmed_manual'
                || ! str_starts_with((string) ($product['source_reference'] ?? ''), 'product-cost-source:v1:'))) {
            $product = $this->blocked('product_cost', 'confirmed_product_cost_required_for_p2_gate');
        }
        $candidates = [$product, $this->firstMileCandidate($order)];
        if ($scopeBlocker !== null) {
            $candidates = array_map(fn (array $candidate): array => $this->forceBlocked($candidate, $scopeBlocker), $candidates);
        }

        return [
            'order_id' => (int) $order->getKey(),
            'scope_status' => $scopeBlocker === null ? 'operational' : 'blocked',
            'scope_blocker' => $scopeBlocker,
            'candidates' => array_values(array_map(fn (array $candidate): array => $this->annotateDisposition($order, $candidate), $candidates)),
        ];
    }

    /** @return array<string, mixed> */
    public function applyProductCostGate(Model $order): array
    {
        return DB::transaction(function () use ($order): array {
            $locked = $order->newQuery()->lockForUpdate()->findOrFail($order->getKey());
            $preview = $this->previewProductCostGate($locked);
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
                    'finance.product_cost_materialization.applied',
                    $locked::class,
                    $locked->getKey(),
                    after: ['applied' => $result['applied'], 'blocked' => $result['blocked']],
                    reason: 'product_cost_materialization',
                );
            }

            return $result;
        });
    }

    /** @return array<string, mixed> */
    public function applyFixedRules(Model $order): array
    {
        return DB::transaction(function () use ($order): array {
            $locked = $order->newQuery()->lockForUpdate()->findOrFail($order->getKey());
            $preview = $this->previewFixedRules($locked);
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
                    'finance.fixed_cost_materialization.applied',
                    $locked::class,
                    $locked->getKey(),
                    after: ['applied' => $result['applied'], 'blocked' => $result['blocked']],
                    reason: 'fixed_cost_materialization',
                );
            }

            return $result;
        });
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

        $confirmedPresence = array_filter($rows, fn (array $row): bool => ($row['confirmed_cost_sources'] ?? []) !== []);
        if ($confirmedPresence !== []) {
            if (count($confirmedPresence) !== count($rows)) {
                return $this->blocked('product_cost', 'confirmed_product_cost_source_incomplete');
            }

            $totalByCurrency = [];
            $sourceIds = [];
            $sourceKeys = [];
            $sourceAt = null;
            foreach ($rows as $row) {
                $quantity = (string) ($row['quantity'] ?? '');
                if (preg_match('/^[1-9][0-9]*$/D', $quantity) !== 1 || ($row['product_id'] ?? null) === null) {
                    return $this->blocked('product_cost', 'confirmed_product_cost_item_identity_invalid', ['item_id' => $row['item_id'] ?? null]);
                }
                $sources = $row['confirmed_cost_sources'] ?? [];
                $distinct = [];
                foreach ($sources as $source) {
                    $key = strtoupper((string) ($source['currency'] ?? '')).'|'.(string) ($source['unit_cost'] ?? '');
                    $distinct[$key] = $source;
                }
                if (count($distinct) !== 1) {
                    return $this->blocked('product_cost', 'confirmed_product_cost_source_ambiguous', ['item_id' => $row['item_id'] ?? null]);
                }
                $source = array_values($distinct)[0];
                $sourceCurrency = strtoupper((string) $source['currency']);
                $line = DecimalMoney::multiply((string) $source['unit_cost'], $quantity, 8);
                $totalByCurrency[$sourceCurrency] = DecimalMoney::add($totalByCurrency[$sourceCurrency] ?? '0', $line, 8);
                foreach ($sources as $evidence) {
                    $sourceIds[] = (int) $evidence['id'];
                    $sourceKeys[] = (string) $evidence['source_key'];
                    $candidateSourceAt = $evidence['source_at'] ?? $evidence['confirmed_at'] ?? null;
                    if ($candidateSourceAt !== null && ($sourceAt === null || $candidateSourceAt > $sourceAt)) {
                        $sourceAt = $candidateSourceAt;
                    }
                }
            }
            if (count($totalByCurrency) !== 1) {
                return $this->blocked('product_cost', 'confirmed_product_cost_mixed_source_currency', ['source_currencies' => array_keys($totalByCurrency)]);
            }
            $sourceCurrency = array_key_first($totalByCurrency);
            sort($sourceIds);
            sort($sourceKeys);
            $sourceAmount = DecimalMoney::round($totalByCurrency[$sourceCurrency]);
            $extra = ['source_ids' => array_values(array_unique($sourceIds)), 'source_keys' => array_values(array_unique($sourceKeys))];
            $amount = $sourceAmount;
            if ($sourceCurrency !== $currency) {
                $fx = $this->fxBridge->preview($order, $sourceCurrency);
                if (! ($fx['available'] ?? false)) {
                    return $this->blocked('product_cost', 'confirmed_product_cost_fx_snapshot_unavailable', $extra + [
                        'source_currency' => $sourceCurrency,
                        'order_currency' => $currency,
                        'source_amount' => $sourceAmount,
                        'fx_reason' => $fx['reason'] ?? 'fx_unavailable',
                    ]);
                }
                $amount = DecimalMoney::round(DecimalMoney::multiply($sourceAmount, (string) $fx['source_to_transaction_rate'], 8));
                $extra += [
                    'source_currency' => $sourceCurrency,
                    'source_amount' => $sourceAmount,
                    'posting_fx' => $fx + [
                        'base_amount' => DecimalMoney::round(DecimalMoney::multiply($sourceAmount, (string) $fx['source_to_usd_rate'], 8)),
                    ],
                ];
            }

            return $this->candidate(
                'product_cost', $amount, $currency, 'confirmed_manual', 'confirmed',
                'product-cost-source:v1:order:'.$order->getKey().':'.hash('sha256', implode(',', $sourceIds)), $sourceAt, $extra,
            );
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

    private function lastMileEstimateCandidate(Model $order): array
    {
        $actual = $this->carrier->read($order->getKey());
        if (is_array($actual) && ! isset($actual['blocked'])) {
            $identity = $actual['billing_document_number'] ?? $actual['provider_reference'] ?? $actual['data_source'] ?? null;
            if ($actual['actual_cost'] !== null && $actual['currency'] !== null && is_string($identity) && trim($identity) !== '') {
                return $this->blocked('last_mile_cost', 'actual_carrier_billing_available');
            }
        }

        $evidence = $this->lastMileEstimates->read($order->getKey());
        if ($evidence === null || isset($evidence['blocked'])) {
            return $this->blocked('last_mile_cost', (string) ($evidence['blocked'] ?? 'last_mile_estimate_missing'));
        }
        $sourceAmount = DecimalMoney::round((string) $evidence['amount']);
        $sourceCurrency = strtoupper((string) ($evidence['currency'] ?? ''));
        if ($sourceCurrency === '') return $this->blocked('last_mile_cost', 'last_mile_estimate_currency_missing');

        $currency = $this->orderCurrency($order);
        $amount = $sourceAmount;
        $extra = [
            'source_currency' => $sourceCurrency,
            'source_amount' => $sourceAmount,
            'selected_source' => $evidence['selected_source'] ?? null,
        ];
        if ($sourceCurrency !== $currency) {
            $fx = $this->fxBridge->preview($order, $sourceCurrency);
            if (! ($fx['available'] ?? false)) {
                return $this->blocked('last_mile_cost', 'last_mile_estimate_fx_snapshot_unavailable', $extra + ['fx_reason' => $fx['reason'] ?? 'fx_unavailable']);
            }
            $amount = DecimalMoney::round(DecimalMoney::multiply($sourceAmount, (string) $fx['source_to_transaction_rate'], 8));
            $extra['posting_fx'] = $fx + [
                'base_amount' => DecimalMoney::round(DecimalMoney::multiply($sourceAmount, (string) $fx['source_to_usd_rate'], 8)),
            ];
        }

        return $this->candidate(
            'last_mile_cost', $amount, $currency, 'estimated_reference', 'estimated',
            'last-mile-estimate:v1:order:'.$order->getKey(), $evidence['source_at'] ?? null, $extra,
        );
    }

    private function carrierCandidate(Model $order): array
    {
        $evidence = $this->carrier->read($order->getKey());
        if ($evidence === null || isset($evidence['blocked'])) {
            return $this->blocked('last_mile_cost', (string) ($evidence['blocked'] ?? 'carrier_evidence_missing'));
        }

        $currency = $this->orderCurrency($order);
        $sourceCurrency = strtoupper(trim((string) ($evidence['currency'] ?? '')));
        $identity = $evidence['billing_document_number'] ?? $evidence['provider_reference'] ?? $evidence['data_source'] ?? null;
        if ($evidence['actual_cost'] === null || $sourceCurrency === '' || ! is_string($identity) || trim($identity) === '') {
            return $this->blocked('last_mile_cost', 'complete_carrier_billing_evidence_required');
        }

        $sourceAmount = DecimalMoney::round((string) $evidence['actual_cost']);
        $amount = $sourceAmount;
        $extra = [
            'source_currency' => $sourceCurrency,
            'source_amount' => $sourceAmount,
            'shipment_id' => $evidence['shipment_id'],
            'billing_id' => $evidence['billing_id'],
            'billing_document_number' => $evidence['billing_document_number'] ?? null,
            'provider_reference' => $evidence['provider_reference'] ?? null,
            'data_source' => $evidence['data_source'] ?? null,
        ];
        if ($sourceCurrency !== $currency) {
            $fx = $this->fxBridge->preview($order, $sourceCurrency);
            if (! ($fx['available'] ?? false)) {
                return $this->blocked('last_mile_cost', 'carrier_billing_fx_snapshot_unavailable', $extra + ['fx_reason' => $fx['reason'] ?? 'fx_unavailable']);
            }
            $amount = DecimalMoney::round(DecimalMoney::multiply($sourceAmount, (string) $fx['source_to_transaction_rate'], 8));
            $extra['posting_fx'] = $fx + [
                'base_amount' => DecimalMoney::round(DecimalMoney::multiply($sourceAmount, (string) $fx['source_to_usd_rate'], 8)),
            ];
        }

        return $this->candidate(
            'last_mile_cost', $amount, $currency, 'provider_import', 'confirmed',
            'carrier-billing:v1:shipment:'.$evidence['shipment_id'].':billing:'.$evidence['billing_id'],
            $evidence['billed_at'] ?? $evidence['updated_at'] ?? null, $extra,
        );
    }

    private function firstMileCandidate(Model $order): array
    {
        $evidence = $this->fulfillmentOrigin->read($order->getKey());
        if ($evidence === null || isset($evidence['blocked'])) {
            return $this->blocked('first_mile_cost', (string) ($evidence['blocked'] ?? 'fulfillment_origin_missing'));
        }

        $slug = strtolower(trim((string) ($evidence['warehouse_slug'] ?? '')));
        $currency = $this->orderCurrency($order);
        $context = [
            'warehouse_id' => $evidence['warehouse_id'] ?? null,
            'warehouse_slug' => $slug,
            'shipment_id' => $evidence['shipment_id'] ?? null,
        ];

        if (in_array($slug, self::CHINA_DIRECT_WAREHOUSE_SLUGS, true)) {
            return $this->candidate(
                'first_mile_cost', '0.00', $currency, 'confirmed_manual', 'confirmed',
                'owner-rule:first-mile:v1:china-direct:'.$slug, null,
                $context + ['rule' => 'china_direct_zero'],
            );
        }

        if (in_array($slug, self::OVERSEAS_WAREHOUSE_SLUGS, true)) {
            $amount = '1.00';
            $extra = $context + ['rule' => 'overseas_warehouse_usd_one_per_order'];
            if ($currency !== 'USD') {
                $fx = $this->fxBridge->preview($order, 'USD');
                if (! ($fx['available'] ?? false)) {
                    return $this->blocked('first_mile_cost', 'first_mile_fx_snapshot_unavailable', $context + [
                        'source_currency' => 'USD',
                        'order_currency' => $currency,
                        'source_amount' => '1.00',
                        'fx_reason' => $fx['reason'] ?? 'fx_unavailable',
                    ]);
                }
                $amount = DecimalMoney::round(DecimalMoney::multiply('1.00', (string) $fx['source_to_transaction_rate'], 8));
                $extra += [
                    'source_currency' => 'USD',
                    'source_amount' => '1.00',
                    'posting_fx' => $fx + ['base_amount' => '1.00'],
                ];
            }

            return $this->candidate(
                'first_mile_cost', $amount, $currency, 'confirmed_manual', 'confirmed',
                'owner-rule:first-mile:v1:overseas:'.$slug, null, $extra,
            );
        }

        return $this->blocked('first_mile_cost', 'first_mile_warehouse_classification_unknown', $context);
    }

    private function paymentFeeCandidate(Model $order): array
    {
        $financials = $order->financials()->first();
        if ($financials === null) {
            return $this->blocked('payment_fee', 'payment_fee_order_financials_missing');
        }
        if ($financials->provider_order_total === null) {
            return $this->blocked('payment_fee', 'payment_fee_provider_order_total_missing');
        }

        $providerTotal = trim((string) $financials->provider_order_total);
        if (! preg_match('/^-?\d+(?:\.\d+)?$/D', $providerTotal)) {
            return $this->blocked('payment_fee', 'payment_fee_provider_order_total_invalid');
        }
        if (bccomp($providerTotal, '0', 8) < 0) {
            return $this->blocked('payment_fee', 'payment_fee_provider_order_total_negative', ['provider_order_total' => $providerTotal]);
        }

        $currency = $this->orderCurrency($order);
        $amount = DecimalMoney::round(DecimalMoney::multiply($providerTotal, self::PAYMENT_FEE_RATE, 8));
        $extra = [
            'rule' => 'provider_order_total_x_3_5_percent',
            'fee_rate' => self::PAYMENT_FEE_RATE,
            'basis' => 'provider_order_total',
            'basis_amount' => DecimalMoney::round($providerTotal),
            'refund_policy' => 'separate_no_fee_reversal',
        ];

        if ($currency !== 'USD') {
            $fx = $this->fxBridge->preview($order, $currency);
            if (! ($fx['available'] ?? false)) {
                return $this->blocked('payment_fee', 'payment_fee_fx_snapshot_unavailable', $extra + [
                    'order_currency' => $currency,
                    'fx_reason' => $fx['reason'] ?? 'fx_unavailable',
                ]);
            }
            $extra['posting_fx'] = $fx + [
                'base_amount' => DecimalMoney::round(DecimalMoney::multiply($amount, (string) $fx['source_to_usd_rate'], 8)),
            ];
        }

        return $this->candidate(
            'payment_fee', $amount, $currency, 'confirmed_manual', 'confirmed',
            'owner-rule:payment-fee:v1:provider-order-total:3.5pct',
            $financials->provider_modified_at ?? $financials->paid_at ?? null,
            $extra,
        );
    }

    private function customsCandidate(Model $order): array
    {
        return $this->candidate(
            'customs_tax', '0.00', $this->orderCurrency($order), 'confirmed_manual', 'confirmed',
            'owner-rule:customs-tax:v1:none', null, ['rule' => 'no_customs_tax'],
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
        if (isset($candidate['posting_fx'])) {
            $fx = $candidate['posting_fx'];
            $this->fxBridge->freezeTransactionSnapshot($order, $fx);
            $values += [
                'exchange_rate' => $fx['source_to_usd_rate'],
                'exchange_rate_date' => $fx['source_exchange_rate_date'],
                'exchange_rate_source' => $fx['source_exchange_rate_source'],
                'base_amount' => $fx['base_amount'],
                'base_currency' => 'USD',
            ];
        }
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
        if ((string) $entry->source_reference === (string) $candidate['source_reference']
            && (string) $entry->amount === (string) $candidate['amount']
            && strtoupper((string) $entry->currency) === strtoupper((string) $candidate['currency'])
            && (string) $entry->provenance === (string) $candidate['provenance']
            && (string) $entry->status === (string) $candidate['status']
            && $this->fxSnapshotMatches($candidate, $entry)) {
            return ($candidate['posting_fx']['freeze_transaction_fx'] ?? false) ? 'apply' : 'noop';
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
        return 'apply';
    }


    private function fxSnapshotMatches(array $candidate, Model $entry): bool
    {
        if (! isset($candidate['posting_fx'])) {
            return true;
        }
        $fx = $candidate['posting_fx'];
        return (string) $entry->exchange_rate === (string) $fx['source_to_usd_rate']
            && optional($entry->exchange_rate_date)->toDateString() === (string) $fx['source_exchange_rate_date']
            && (string) $entry->exchange_rate_source === (string) $fx['source_exchange_rate_source']
            && (string) $entry->base_amount === (string) $fx['base_amount']
            && strtoupper((string) $entry->base_currency) === 'USD';
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
            || str_starts_with($source, 'last-mile-estimate:v1:')
            || str_starts_with($source, 'affiliatewp-referrals:v1:')
            || str_starts_with($source, 'product-cost-source:v1:')
            || str_starts_with($source, 'owner-rule:payment-fee:v1:');
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
