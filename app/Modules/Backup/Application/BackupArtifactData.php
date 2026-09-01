<?php

namespace App\Modules\Backup\Application;

final readonly class BackupArtifactData
{
    public function __construct(
        public int $id,
        public int $runId,
        public string $path,
    ) {}
}
