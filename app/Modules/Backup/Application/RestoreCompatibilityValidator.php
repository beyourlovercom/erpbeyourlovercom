<?php

namespace App\Modules\Backup\Application;

use App\Modules\Backup\Application\Contracts\RestoreCompatibility;
use Throwable;

final readonly class RestoreCompatibilityValidator
{
    public function __construct(private RestoreCompatibility $current) {}

    /** @return list<string> */
    public function reasonCodes(array $manifest): array
    {
        $reasons = [];
        if (($manifest['backup_format_version'] ?? null) !== 1) {
            $reasons[] = 'format_mismatch';
        }

        try {
            $currentGitSha = $this->current->gitSha();
            $currentEngine = $this->current->databaseEngine();
            $currentLatestMigration = $this->current->latestMigration();
            $currentMigrationCount = $this->current->migrationCount();
        } catch (Throwable) {
            return [...$reasons, 'current_compatibility_unavailable'];
        }

        $manifestGitSha = $manifest['git_sha'] ?? null;
        if (! is_string($currentGitSha) || ! preg_match('/^[0-9a-f]{40}$/', $currentGitSha)) {
            $reasons[] = 'current_git_sha_unknown';
        } elseif (! is_string($manifestGitSha) || ! preg_match('/^[0-9a-f]{40}$/', $manifestGitSha)) {
            $reasons[] = 'git_sha_invalid';
        } elseif ($manifestGitSha !== $currentGitSha) {
            $reasons[] = 'git_sha_mismatch';
        }

        $manifestEngine = $manifest['database_engine'] ?? null;
        if (! is_string($currentEngine) || trim($currentEngine) === '') {
            $reasons[] = 'current_database_engine_unknown';
        } elseif (! is_string($manifestEngine) || trim($manifestEngine) === '') {
            $reasons[] = 'database_engine_invalid';
        } elseif ($manifestEngine !== $currentEngine) {
            $reasons[] = 'database_engine_mismatch';
        }

        $manifestMigrationCount = $manifest['migration_count'] ?? null;
        $manifestLatestMigration = $manifest['latest_migration'] ?? null;
        if ($currentMigrationCount < 0 || ($currentMigrationCount > 0 && (! is_string($currentLatestMigration) || trim($currentLatestMigration) === ''))) {
            $reasons[] = 'current_migration_ledger_unknown';
        } elseif (! is_int($manifestMigrationCount) || $manifestMigrationCount < 0 || ($manifestMigrationCount > 0 && (! is_string($manifestLatestMigration) || trim($manifestLatestMigration) === ''))) {
            $reasons[] = 'migration_ledger_invalid';
        } else {
            if ($manifestLatestMigration !== $currentLatestMigration) {
                $reasons[] = 'latest_migration_mismatch';
            }
            if ($manifestMigrationCount !== $currentMigrationCount) {
                $reasons[] = 'migration_count_mismatch';
            }
        }

        return array_values(array_unique($reasons));
    }

    public function assertCompatible(array $manifest): void
    {
        $reasons = $this->reasonCodes($manifest);
        if ($reasons !== []) {
            throw new RestoreFailure($reasons[0]);
        }
    }
}
