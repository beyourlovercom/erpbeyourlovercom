<?php

namespace App\Support\Runtime;

use Illuminate\Contracts\Config\Repository;

class RuntimeHealthService
{
    public function __construct(
        private readonly Repository $config,
        private readonly RedisClientCapability $clientCapability,
        private readonly RedisRuntimeProbe $redis,
    ) {}

    /**
     * @return array{status: 'ok'|'degraded', checks: array<string, array{status: 'ok'|'degraded', code: string}>}
     */
    public function check(): array
    {
        $clientCode = $this->clientCapability->code((string) $this->config->get('database.redis.client'));
        $checks = [
            'cache_configuration' => $this->result(
                $this->config->get('cache.default') === 'redis',
                'configured',
                'expected_redis',
            ),
            'queue_configuration' => $this->result(
                $this->config->get('queue.default') === 'redis',
                'configured',
                'expected_redis',
            ),
            'redis_client' => $this->result($clientCode === 'supported', 'supported', $clientCode),
            'redis_default' => $this->connectionResult('default', $clientCode),
            'redis_cache' => $this->connectionResult('cache', $clientCode),
            'queue_contract' => $this->queueContractResult(),
        ];

        return [
            'status' => collect($checks)->every(fn (array $check): bool => $check['status'] === 'ok') ? 'ok' : 'degraded',
            'checks' => $checks,
        ];
    }

    /** @return array{status: 'ok'|'degraded', code: string} */
    private function connectionResult(string $connection, string $clientCode): array
    {
        if ($clientCode !== 'supported') {
            return $this->result(false, 'reachable', 'client_unavailable');
        }

        return $this->result(
            $this->redis->connectionIsAvailable($connection),
            'reachable',
            'unavailable',
        );
    }

    /** @return array{status: 'ok'|'degraded', code: string} */
    private function queueContractResult(): array
    {
        $retryAfter = $this->config->get('queue.connections.redis.retry_after');
        $blockFor = $this->config->get('queue.connections.redis.block_for');
        $names = array_map(fn (QueueName $queue): string => $queue->value, QueueName::cases());
        $valid = is_int($retryAfter)
            && is_int($blockFor)
            && $retryAfter > 0
            && $blockFor > 0
            && $blockFor < $retryAfter
            && count($names) === count(array_unique($names))
            && collect($names)->every(fn (string $name): bool => trim($name) !== '');

        return $this->result($valid, 'valid', 'invalid');
    }

    /** @return array{status: 'ok'|'degraded', code: string} */
    private function result(bool $healthy, string $healthyCode, string $degradedCode): array
    {
        return [
            'status' => $healthy ? 'ok' : 'degraded',
            'code' => $healthy ? $healthyCode : $degradedCode,
        ];
    }
}
