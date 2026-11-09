<?php

namespace Tests\Unit\Logistics;

use App\Modules\Logistics\Application\LogisticsTrackingListStatus;
use App\Modules\Sync\Application\ManualSyncStatus;
use PHPUnit\Framework\TestCase;

final class LogisticsTrackingListStatusTest extends TestCase
{
    public function test_manual_sync_completed_without_remote_proof_does_not_claim_tracking_synced(): void
    {
        $status = new LogisticsTrackingListStatus;
        self::assertSame(
            ['status' => 'pending', 'reason' => 'ERP 已标记完成，但没有官网写回验证记录'],
            $status->currentStatus(null, ManualSyncStatus::Completed),
        );
        self::assertSame(
            ['status' => 'pending', 'reason' => 'ERP 已标记完成，但没有官网写回验证记录'],
            $status->currentStatus(null, 'completed'),
        );
        self::assertSame(
            ['status' => 'completed', 'reason' => null, 'verified_at' => null],
            $status->currentStatus(['verified_manual' => true], ManualSyncStatus::Completed),
        );
    }

    public function test_outbox_completed_never_overrides_uncompleted_canonical_enum(): void
    {
        $status = new LogisticsTrackingListStatus;
        foreach ([ManualSyncStatus::Pending, ManualSyncStatus::Failed, ManualSyncStatus::Returned, ManualSyncStatus::NeedsConfirmation, ManualSyncStatus::NotRequired, null] as $manualStatus) {
            self::assertSame('pending', $status->currentStatus(['status' => 'completed', 'reason' => null], $manualStatus)['status']);
        }
    }

    public function test_all_canonical_enum_values_match_their_serialized_strings(): void
    {
        $status = new LogisticsTrackingListStatus;
        foreach (ManualSyncStatus::cases() as $manualStatus) {
            foreach ([null, ['status' => 'pending', 'reason' => 'retry'], ['status' => 'failed', 'reason' => 'error'], ['status' => 'completed', 'reason' => null]] as $outbox) {
                self::assertSame($status->currentStatus($outbox, $manualStatus->value), $status->currentStatus($outbox, $manualStatus), $manualStatus->name);
            }
        }
    }
}
