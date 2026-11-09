<?php

namespace App\Modules\Logistics\Application\ShipOut;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/** All manual and batch Production V1 POSTs share one conservative provider pace. */
final class ShipOutProductionOutboundPacer
{
    public function claim(): void
    {
        $mutex = Cache::lock('shipout:production:outbound:pace:mutex', 8);
        if (! $mutex->get()) {
            throw new RuntimeException('SHIPOUT_V1_SUBMISSION_RATE_LIMITED');
        }
        try {
            $key = 'shipout:production:outbound:pace:global';
            if (RateLimiter::tooManyAttempts($key, 1)) {
                throw new RuntimeException('SHIPOUT_V1_SUBMISSION_RATE_LIMITED');
            }
            RateLimiter::hit($key, 60);
        } finally {
            $mutex->release();
        }
    }
}
