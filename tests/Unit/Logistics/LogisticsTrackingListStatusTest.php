<?php

namespace Tests\Unit\Logistics;

use App\Modules\Logistics\Application\LogisticsTrackingListStatus;
use App\Modules\Sync\Application\ManualSyncStatus;
use PHPUnit\Framework\TestCase;

final class LogisticsTrackingListStatusTest extends TestCase
{
    public function test_completed_enum_or_string_marks_current_tracking_completed(): void
    {
        $status = new LogisticsTrackingListStatus();
        $expected = ['status' => 'completed', 'reason' => null];
        self::assertSame($expected, $status->currentStatus(null, ManualSyncStatus::Completed));
        self::assertSame($expected, $status->currentStatus(null, 'completed'));
        self::assertSame($expected, $status->currentStatus(['status' => 'completed', 'reason' => null], ManualSyncStatus::Completed));
    }

    public function test_outbox_completed_never_overrides_uncompleted_canonical_enum(): void
    {
        $status = new LogisticsTrackingListStatus();
        foreach ([ManualSyncStatus::Pending, ManualSyncStatus::Failed, ManualSyncStatus::Returned, ManualSyncStatus::NeedsConfirmation, ManualSyncStatus::NotRequired, null] as $manualStatus) {
            self::assertSame(['status' => 'pending', 'reason' => null], $status->currentStatus(['status' => 'completed', 'reason' => null], $manualStatus));
        }
    }

    public function test_all_canonical_enum_values_match_their_serialized_strings(): void
    {
        $status = new LogisticsTrackingListStatus();
        foreach (ManualSyncStatus::cases() as $manualStatus) {
            foreach ([null, ['status' => 'pending', 'reason' => 'retry'], ['status' => 'failed', 'reason' => 'error'], ['status' => 'completed', 'reason' => null]] as $outbox) {
                self::assertSame($status->currentStatus($outbox, $manualStatus->value), $status->currentStatus($outbox, $manualStatus), $manualStatus->name);
            }
        }
    }
}
