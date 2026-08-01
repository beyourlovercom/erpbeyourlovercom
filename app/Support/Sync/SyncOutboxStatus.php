<?php

namespace App\Support\Sync;

enum SyncOutboxStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
