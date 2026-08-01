<?php

namespace App\Support\Audit;

use RuntimeException;

final class AuditWriteException extends RuntimeException
{
    public static function failed(): self
    {
        return new self('A required audit record could not be written.');
    }
}
