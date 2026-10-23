<?php

namespace App\Modules\B2B\Sales;

use InvalidArgumentException;

/**
 * Read-only financial adapter primitive. Convert BYL OrderFinancial decimal:2
 * strings to exact integer micros without float conversion or currency FX.
 */
final class B2BSalesExactMoney
{
    public static function decimalToMicros(string $amount): int
    {
        if (! preg_match('/^(0|[1-9][0-9]{0,10})(?:\.([0-9]{1,2}))?$/D', $amount, $matches)) {
            throw new InvalidArgumentException('Expected a nonnegative decimal amount with at most two fractional digits.');
        }

        $whole = (int) $matches[1];
        $fraction = str_pad($matches[2] ?? '', 2, '0');
        $cents = $whole * 100 + (int) $fraction;

        return $cents * 10000;
    }

    public static function netMicros(string $amountPaid, string $refundAmount): int
    {
        $paid = self::decimalToMicros($amountPaid);
        $refund = self::decimalToMicros($refundAmount);
        if ($refund > $paid) {
            throw new InvalidArgumentException('Refund cannot exceed the paid amount.');
        }

        return $paid - $refund;
    }
}
