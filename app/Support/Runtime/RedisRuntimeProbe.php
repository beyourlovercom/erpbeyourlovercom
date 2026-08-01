<?php

namespace App\Support\Runtime;

use Illuminate\Redis\RedisManager;

class RedisRuntimeProbe
{
    public function __construct(private readonly RedisManager $redis) {}

    public function connectionIsAvailable(string $connection): bool
    {
        try {
            return $this->redis->connection($connection)->command('ping') !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
