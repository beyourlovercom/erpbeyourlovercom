<?php

namespace Tests\Unit\B2B;

use App\Modules\B2B\Sales\B2BSalesExactMoney;
use App\Modules\B2B\Sales\B2BSalesOrderAttributionGate;
use App\Modules\Orders\Application\OrderKind;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class B2BSalesOrderAttributionGateTest extends TestCase
{
    /** @return array<string,mixed> */
    private function context(string $kind = 'customer'): array
    {
        return [
            'order_id' => 123,
            'organization_id' => 7,
            'opportunity_id' => 32,
            'order_kind' => $kind,
        ];
    }

    public function test_all_current_order_kinds_are_blocked(): void
    {
        $this->assertSame(['customer','kol_fulfillment','fulfillment_derived'],
            array_map(fn (OrderKind $kind) => $kind->value, OrderKind::cases()));

        $gate = new B2BSalesOrderAttributionGate();
        foreach (OrderKind::cases() as $kind) {
            $decision = $gate->evaluate($this->context($kind->value));
            $this->assertFalse($decision['eligible']);
            $this->assertSame('consumer_or_fulfillment_order_not_b2b', $decision['reason']);
            $this->assertTrue($decision['requires_finance_owner_review']);
        }
    }

    public function test_forged_future_trade_order_kind_does_not_grant_payment_access(): void
    {
        $actual = (new B2BSalesOrderAttributionGate())->evaluate($this->context('b2b_trade'));
        $this->assertFalse($actual['eligible']);
        $this->assertSame('unregistered_order_kind', $actual['reason']);
    }

    public function test_missing_business_link_ids_never_authorize_order(): void
    {
        $gate = new B2BSalesOrderAttributionGate();
        foreach (['order_id', 'organization_id', 'opportunity_id'] as $field) {
            $bad = $this->context();
            unset($bad[$field]);
            $this->assertSame('missing_canonical_business_identity', $gate->evaluate($bad)['reason']);
        }
        $bad = $this->context();
        $bad['organization_id'] = '7';
        $this->assertFalse($gate->evaluate($bad)['eligible']);
    }

    public function test_fake_order_kind_or_email_amount_guess_is_rejected(): void
    {
        $gate = new B2BSalesOrderAttributionGate();
        $candidate = $this->context('manual_b2b');
        $candidate['buyer_email'] = 'same-customer@example.invalid';
        $candidate['estimated_quote_usd'] = '100.00';
        $this->assertSame('unregistered_order_kind', $gate->evaluate($candidate)['reason']);
        $this->assertFalse($gate->evaluate($candidate)['eligible']);
    }

    public function test_decimal_to_micros_is_exact_for_byL_finance_decimal_2(): void
    {
        $this->assertSame(0, B2BSalesExactMoney::decimalToMicros('0'));
        $this->assertSame(10000, B2BSalesExactMoney::decimalToMicros('0.01'));
        $this->assertSame(1000000, B2BSalesExactMoney::decimalToMicros('1'));
        $this->assertSame(1200000, B2BSalesExactMoney::decimalToMicros('1.20'));
        $this->assertSame(1234567890000, B2BSalesExactMoney::decimalToMicros('1234567.89'));
        $this->assertSame(1050000, B2BSalesExactMoney::netMicros('1.25','0.20'));
    }

    public function test_money_rejects_float_like_strings_unexpected_precision_and_negatives(): void
    {
        foreach (['-1.00','+1.00','1.234','1e5','1,000.00',' 1.00','01.00','1.','-0.01','99999999999999.99'] as $bad) {
            try {
                B2BSalesExactMoney::decimalToMicros($bad);
                $this->fail('Invalid money accepted: '.$bad);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_negative_net_from_refund_is_blocked(): void
    {
        $this->expectException(InvalidArgumentException::class);
        B2BSalesExactMoney::netMicros('8.00', '8.01');
    }
}
