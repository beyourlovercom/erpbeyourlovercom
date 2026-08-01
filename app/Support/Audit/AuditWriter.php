<?php

namespace App\Support\Audit;

use App\Models\AuditRecord;

interface AuditWriter
{
    /** @param array<string, mixed> $attributes */
    public function write(array $attributes): AuditRecord;
}
