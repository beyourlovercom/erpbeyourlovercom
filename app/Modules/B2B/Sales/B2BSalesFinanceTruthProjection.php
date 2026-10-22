<?php

namespace App\Modules\B2B\Sales;

/**
 * S3 contract only: pure read-only reconciliation from already validated evidence.
 * It NEVER looks up Woo by fuzzy email/company, writes money, records receipts, or
 * treats a Won Opportunity / Quote draft as financial evidence.
 *
 * Future BYL Finance adapter must verify account-scoped B2B Order linkage,
 * source ownership, paid_at, amounts, refunds, currencies, and approval
 * BEFORE passing immutable evidence to this projector.
 */
final class B2BSalesFinanceTruthProjection
{
    /**
     * @param array{source?:string,order_id?:int,financial_id?:int,verified_at?:string,approved_by?:int}|null $link
     * @param array{amount_micros:int,currency:string,confirmed_at?:string}|null $receivable
     * @param list<array<string,mixed>> $facts Verified immutable OrderFinancial facts from future read-only adapter.
     * @return array<string,mixed>
     */
    public function summarize(?array $link, ?array $receivable, array $facts = []): array
    {
        $base = [
            'source' => 'byl_order_financial',
            'status' => 'unlinked',
            'receivable_micros' => null,
            'currency' => null,
            'verified_cash_micros' => null,
            'outstanding_micros' => null,
            'overpaid_micros' => null,
            'finance_link_verified' => false,
            'facts_count' => 0,
            'actual_revenue_recorded' => false, // projection never owns financial posting
            'reason' => 'no_verified_b2b_order_link',
        ];

        // Never auto-match a consumer Woo order by company, email, amount or Won.
        if ($link === null) {
            return $base;
        }
        if (($link['source'] ?? '') !== 'byl_order_financial'
            || (int) ($link['order_id'] ?? 0) <= 0
            || (int) ($link['financial_id'] ?? 0) <= 0
            || (int) ($link['approved_by'] ?? 0) <= 0
            || trim((string) ($link['verified_at'] ?? '')) === '') {
            return [...$base, 'reason' => 'finance_link_not_attested'];
        }

        if ($receivable === null) {
            return [
                ...$base, 'status' => 'pending_receivable',
                'finance_link_verified' => true, 'reason' => 'no_approved_receivable_baseline',
            ];
        }

        $amount = $receivable['amount_micros'] ?? null;
        $currency = strtoupper(trim((string) ($receivable['currency'] ?? '')));
        if (! is_int($amount) || $amount <= 0 || ! preg_match('/^[A-Z]{3}$/', $currency)
            || trim((string) ($receivable['confirmed_at'] ?? '')) === '') {
            return [
                ...$base, 'status' => 'review_required',
                'finance_link_verified' => true, 'reason' => 'invalid_receivable_confirmation',
            ];
        }

        $confirmed = 0;
        $refs = [];
        foreach ($facts as $row) {
            $ref = trim((string) ($row['transaction_reference'] ?? ''));
            $paid = $row['paid_micros'] ?? null;
            $refund = $row['refunded_micros'] ?? null;
            if ((int) ($row['order_id'] ?? 0) !== (int) $link['order_id']
                || (int) ($row['financial_id'] ?? 0) !== (int) $link['financial_id']
                || strtoupper((string) ($row['currency'] ?? '')) !== $currency
                || ! is_int($paid) || ! is_int($refund)
                || $paid < 0 || $refund < 0 || $refund > $paid
                || trim((string) ($row['paid_at'] ?? '')) === ''
                || $ref === '' || isset($refs[$ref])) {
                return [
                    ...$base, 'status' => 'review_required',
                    'receivable_micros' => $amount, 'currency' => $currency,
                    'finance_link_verified' => true, 'reason' => 'unreconciled_or_duplicate_payment_evidence',
                ];
            }
            $refs[$ref] = true;
            $confirmed += ($paid - $refund);
        }

        return [
            ...$base,
            'status' => match (true) {
                $confirmed === 0 => 'unpaid',
                $confirmed < $amount => 'partial',
                $confirmed === $amount => 'paid',
                default => 'overpaid',
            },
            'receivable_micros' => $amount,
            'currency' => $currency,
            'verified_cash_micros' => $confirmed,
            'outstanding_micros' => max(0, $amount - $confirmed),
            'overpaid_micros' => max(0, $confirmed - $amount),
            'finance_link_verified' => true,
            'facts_count' => count($refs),
            'reason' => null,
        ];
    }
}
