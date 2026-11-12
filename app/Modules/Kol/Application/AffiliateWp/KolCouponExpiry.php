<?php

namespace App\Modules\Kol\Application\AffiliateWp;

use Carbon\CarbonImmutable;

final class KolCouponExpiry
{
    // Coupon remains usable until the end of the selected UTC calendar day.
    public static function resolve(?string $joinedDate, ?string $selectedDate): string
    {
        $base = CarbonImmutable::parse($selectedDate ?: ($joinedDate ?: now('UTC')->toDateString()), 'UTC')->startOfDay();
        $expiry = $selectedDate ? $base : $base->addYearsNoOverflow(3);

        return $expiry->endOfDay()->toIso8601String();
    }
}
