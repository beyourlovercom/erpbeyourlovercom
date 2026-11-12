<?php

namespace Tests\Unit;

use App\Modules\Sync\Application\ShipmentUtcTimestamp;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class ShipmentUtcTimestampTest extends TestCase
{
    public function test_mysql_timestamp_epoch_is_stable_across_pacific_standard_and_daylight_time(): void
    {
        $novemberEpoch = CarbonImmutable::parse('2026-11-09 20:12:14', 'America/Los_Angeles')->getTimestamp();
        $octoberEpoch = CarbonImmutable::parse('2026-10-13 09:17:00', 'America/Los_Angeles')->getTimestamp();

        self::assertSame('2026-11-10T04:12:14+00:00', ShipmentUtcTimestamp::fromEpoch($novemberEpoch)->toIso8601String());
        self::assertSame('2026-10-13T16:17:00+00:00', ShipmentUtcTimestamp::fromEpoch($octoberEpoch)->toIso8601String());
    }

    public function test_pre_fix_timezone_drift_is_held_even_when_new_canonical_fingerprint_matches(): void
    {
        $old = ['shipped_at' => '2026-11-10T04:12:14+00:00'];
        $new = ['shipped_at' => '2026-11-10T04:12:14+00:00', 'shipped_at_contract' => ShipmentUtcTimestamp::CONTRACT];

        self::assertTrue(ShipmentUtcTimestamp::holdLegacyTimezoneIntent($old, $new, '2026-11-09T20:12:14+00:00'));
        self::assertFalse(ShipmentUtcTimestamp::holdLegacyTimezoneIntent($old, $new, '2026-11-10T04:12:14+00:00'));
        self::assertFalse(ShipmentUtcTimestamp::holdLegacyTimezoneIntent(['shipped_at' => '2026-11-08T04:12:14+00:00'], $new, '2026-11-09T20:12:14+00:00'));
    }

    public function test_new_versioned_tracking_intents_are_not_held_and_old_missing_date_fails_closed(): void
    {
        $new = ['shipped_at' => '2026-11-10T04:12:14+00:00', 'shipped_at_contract' => ShipmentUtcTimestamp::CONTRACT];

        self::assertFalse(ShipmentUtcTimestamp::holdLegacyTimezoneIntent($new, $new, '2026-11-09T20:12:14+00:00'));
        self::assertFalse(ShipmentUtcTimestamp::holdLegacyTimezoneIntent([], $new, '2026-11-09T20:12:14+00:00'));
        self::assertFalse(ShipmentUtcTimestamp::holdLegacyTimezoneIntent(['shipped_at' => $new['shipped_at']], $new, null));
    }
}
