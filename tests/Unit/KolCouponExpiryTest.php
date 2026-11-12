<?php

namespace Tests\Unit;

use App\Modules\Kol\Application\AffiliateWp\KolCouponExpiry;
use PHPUnit\Framework\TestCase;

final class KolCouponExpiryTest extends TestCase
{
    public function test_three_year_default_is_end_of_anniversary_day_utc(): void
    {
        self::assertSame('2029-11-12T23:59:59+00:00', KolCouponExpiry::resolve('2026-11-12', null));
    }

    public function test_leap_day_default_clamps_to_february_28(): void
    {
        self::assertSame('2027-02-28T23:59:59+00:00', KolCouponExpiry::resolve('2024-02-29', null));
    }

    public function test_explicit_extension_overrides_default(): void
    {
        self::assertSame('2032-12-31T23:59:59+00:00', KolCouponExpiry::resolve('2026-11-12', '2032-12-31'));
    }
}
