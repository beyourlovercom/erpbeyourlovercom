<?php

namespace Tests\Unit\B2B;

use App\Modules\B2B\Sales\B2BSalesFinanceTruthProjection;
use PHPUnit\Framework\TestCase;

final class B2BSalesFinanceTruthProjectionTest extends TestCase
{
    private function projector(): B2BSalesFinanceTruthProjection
    {
        return new B2BSalesFinanceTruthProjection();
    }

    private function link(): array
    {
        return ['source' => 'byl_order_financial', 'order_id' => 40, 'financial_id' => 91,
            'verified_at' => '2026-10-22T12:00:00Z', 'approved_by' => 7];
    }

    private function receivable(int $amount = 100000000): array
    {
        return ['amount_micros' => $amount, 'currency' => 'USD', 'confirmed_at' => '2026-10-22T13:00:00Z'];
    }

    private function fact(int $paid, int $refund = 0, string $ref = 'TX-SYNTHETIC-1'): array
    {
        return ['order_id' => 40, 'financial_id' => 91, 'currency' => 'USD',
            'paid_micros' => $paid, 'refunded_micros' => $refund,
            'transaction_reference' => $ref, 'paid_at' => '2026-10-22T14:00:00Z'];
    }

    public function test_without_verified_link_never_claims_paid_or_zero_revenue(): void
    {
        $actual = $this->projector()->summarize(null, $this->receivable(), [$this->fact(100000000)]);
        self::assertSame('unlinked', $actual['status']);
        self::assertNull($actual['verified_cash_micros']);
        self::assertFalse($actual['actual_revenue_recorded']);
        self::assertSame('no_verified_b2b_order_link', $actual['reason']);
    }

    public function test_unauthorized_link_cannot_be_treated_as_financial_evidence(): void
    {
        $link = $this->link(); unset($link['approved_by']);
        $actual = $this->projector()->summarize($link, $this->receivable(), [$this->fact(100000000)]);
        self::assertFalse($actual['finance_link_verified']);
        self::assertSame('finance_link_not_attested', $actual['reason']);
    }

    public function test_link_without_confirmed_receivable_is_pending_not_paid(): void
    {
        $actual = $this->projector()->summarize($this->link(), null, [$this->fact(100000000)]);
        self::assertSame('pending_receivable', $actual['status']);
        self::assertNull($actual['verified_cash_micros']);
    }

    public function test_attested_receivable_without_payment_is_unpaid(): void
    {
        $actual = $this->projector()->summarize($this->link(), $this->receivable());
        self::assertSame('unpaid', $actual['status']);
        self::assertSame(0, $actual['verified_cash_micros']);
        self::assertSame(100000000, $actual['outstanding_micros']);
    }

    public function test_partial_refunded_cash_is_net_of_refund(): void
    {
        $actual = $this->projector()->summarize($this->link(), $this->receivable(), [$this->fact(70000000, 20000000)]);
        self::assertSame('partial', $actual['status']);
        self::assertSame(50000000, $actual['verified_cash_micros']);
        self::assertSame(50000000, $actual['outstanding_micros']);
        self::assertFalse($actual['actual_revenue_recorded']);
    }

    public function test_paid_and_overpaid_are_derived_not_marked_won(): void
    {
        self::assertSame('paid', $this->projector()->summarize($this->link(), $this->receivable(), [$this->fact(100000000)])['status']);
        $extra = $this->projector()->summarize($this->link(), $this->receivable(), [$this->fact(140000000)]);
        self::assertSame('overpaid', $extra['status']);
        self::assertSame(40000000, $extra['overpaid_micros']);
    }

    public function test_duplicate_payment_reference_forces_review_instead_of_double_counting(): void
    {
        $actual = $this->projector()->summarize($this->link(), $this->receivable(),
            [$this->fact(90000000), $this->fact(90000000)]);
        self::assertSame('review_required', $actual['status']);
        self::assertNull($actual['verified_cash_micros']);
        self::assertSame('unreconciled_or_duplicate_payment_evidence', $actual['reason']);
    }

    public function test_wrong_order_currency_or_refund_forces_review(): void
    {
        foreach ([
            array_replace($this->fact(100000000), ['order_id' => 99]),
            array_replace($this->fact(100000000), ['currency' => 'EUR']),
            $this->fact(100000000, 150000000),
        ] as $bad) {
            $actual = $this->projector()->summarize($this->link(), $this->receivable(), [$bad]);
            self::assertSame('review_required', $actual['status']);
            self::assertNull($actual['verified_cash_micros']);
        }
    }
}
