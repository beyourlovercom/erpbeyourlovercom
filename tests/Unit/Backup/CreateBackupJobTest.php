<?php

namespace Tests\Unit\Backup;

use App\Modules\Backup\Application\BackupState;
use App\Modules\Backup\Experience\Jobs\CreateBackupJob;
use PHPUnit\Framework\TestCase;

final class CreateBackupJobTest extends TestCase
{
    public function test_only_queued_runs_are_runnable_until_resumable_execution_exists(): void
    {
        foreach (BackupState::cases() as $state) {
            if ($state === BackupState::Queued) continue;
            $this->assertFalse(CreateBackupJob::isRunnable($state));
        }
        $this->assertTrue(CreateBackupJob::isRunnable(BackupState::Queued));
    }
}
