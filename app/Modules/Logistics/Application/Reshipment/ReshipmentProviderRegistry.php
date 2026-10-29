<?php

namespace App\Modules\Logistics\Application\Reshipment;

use RuntimeException;

final class ReshipmentProviderRegistry
{
    /** @param iterable<ReshipmentProviderAdapter> $adapters */
    public function __construct(private readonly iterable $adapters = []) {}

    public function get(string $key): ReshipmentProviderAdapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->providerKey() === $key) return $adapter;
        }
        throw new RuntimeException('RESHIP_PROVIDER_NOT_SUPPORTED');
    }
}
