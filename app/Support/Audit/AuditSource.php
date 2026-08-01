<?php

namespace App\Support\Audit;

enum AuditSource: string
{
    case Fortify = 'fortify';
    case InternalSystem = 'system';
    case UserProvisioning = 'erp:user:create';
}
