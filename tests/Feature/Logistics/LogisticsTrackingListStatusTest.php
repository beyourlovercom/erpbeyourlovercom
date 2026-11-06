<?php

namespace Tests\Feature\Logistics;

use App\Modules\Logistics\Application\LogisticsTrackingListStatus;
use App\Modules\Sync\Application\ManualSyncStatus;
use Tests\TestCase;

final class LogisticsTrackingListStatusTest extends TestCase
{
    public function test_manual_flag_alone_never_claims_verified_website_sync(): void
    {
        $status = app(LogisticsTrackingListStatus::class)->currentStatus(null, ManualSyncStatus::Completed);
        self::assertSame('pending', $status['status']);
        self::assertStringContainsString('没有官网写回验证记录', $status['reason']);
    }

    public function test_only_verified_current_tracking_outbox_and_manual_status_show_green(): void
    {
        $projection = ['status' => 'completed', 'reason' => null, 'verified_outbox' => true, 'status_outbox_verified' => true, 'outbox_tracking_number' => 'TRACK-CURRENT'];
        self::assertSame('completed', app(LogisticsTrackingListStatus::class)->currentStatus($projection, ManualSyncStatus::Completed)['status']);
        self::assertSame('pending', app(LogisticsTrackingListStatus::class)->currentStatus($projection, ManualSyncStatus::Pending)['status']);
        $trackingOnly = ['status' => 'completed', 'verified_outbox' => false, 'status_outbox_verified' => false, 'outbox_tracking_number' => 'TRACK-CURRENT'];
        self::assertSame('pending', app(LogisticsTrackingListStatus::class)->currentStatus($trackingOnly, ManualSyncStatus::Completed)['status']);
        $partial = ['status' => 'completed', 'tracking_verified' => true, 'status_outbox_status' => 'completed', 'status_outbox_woo_status' => 'processing'];
        self::assertSame('partial', app(LogisticsTrackingListStatus::class)->currentStatus($partial, ManualSyncStatus::Completed)['status']);
    }

    public function test_verified_manual_override_requires_matching_fingerprint_marker(): void
    {
        $projection = ['status' => 'pending', 'reason' => null, 'verified_manual' => true];
        self::assertSame('completed', app(LogisticsTrackingListStatus::class)->currentStatus($projection, ManualSyncStatus::Completed)['status']);
        $stale = ['status' => 'pending', 'reason' => 'Tracking 已变化，请重新同步官网', 'stale_manual_override' => true];
        self::assertSame('pending', app(LogisticsTrackingListStatus::class)->currentStatus($stale, ManualSyncStatus::Completed)['status']);
    }
}
