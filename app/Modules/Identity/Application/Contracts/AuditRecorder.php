<?php

namespace App\Modules\Identity\Application\Contracts;

use App\Support\Audit\AuditActor;

interface AuditRecorder
{
    public function record(
        AuditActor $actor,
        string $action,
        string $entityType,
        int|string|null $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
    ): mixed;
}
