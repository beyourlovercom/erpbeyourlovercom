<?php

namespace App\Modules\Logistics\Application;

final class WarehouseRoutingPolicy
{
    /** @return array<string,mixed>|null */
    public function country(string $country): ?array
    {
        $code = strtoupper(trim($country));
        $policy = config("warehouse_routing.countries.{$code}");
        return is_array($policy) ? $policy : null;
    }

    /** @return list<string> */
    public function automaticCountries(): array
    {
        return collect(config('warehouse_routing.countries', []))
            ->filter(fn ($policy): bool => is_array($policy) && (bool) ($policy['automatic'] ?? false))
            ->keys()->map(fn ($country): string => strtoupper(trim((string) $country)))
            ->filter()->values()->all();
    }

    public function automaticEnabled(string $country): bool
    {
        return (bool) ($this->country($country)['automatic'] ?? false);
    }

    public function strategy(string $country): ?string
    {
        $strategy = $this->country($country)['strategy'] ?? null;
        return is_string($strategy) && $strategy !== '' ? $strategy : null;
    }

    /** @return list<string> */
    public function warehouseCodes(string $country): array
    {
        return collect($this->country($country)['warehouses'] ?? [])
            ->map(fn ($warehouse): string => strtoupper(trim((string) (is_array($warehouse) ? ($warehouse['code'] ?? '') : $warehouse))))
            ->filter()->unique()->values()->all();
    }

    public function fixedWarehouseCode(string $country): ?string
    {
        if ($this->strategy($country) !== 'fixed') return null;
        $codes = $this->warehouseCodes($country);
        return count($codes) === 1 ? $codes[0] : null;
    }

    public function canonicalWarehouseCode(string $warehouse): ?string
    {
        $needle = trim($warehouse);
        if ($needle === '') return null;
        foreach (config('warehouse_routing.aliases', []) as $canonical => $aliases) {
            foreach ((array) $aliases as $alias) {
                if (strcasecmp($needle, trim((string) $alias)) === 0) return strtoupper(trim((string) $canonical));
            }
        }
        return strtoupper($needle);
    }

    /** @return array<string,mixed>|null */
    public function eastZoneRule(string $country): ?array
    {
        $rule = $this->country($country)['rules']['east_zone'] ?? null;
        return is_array($rule) ? $rule : null;
    }

    /** @return list<string> */
    public function fallbackPriority(string $country): array
    {
        return collect($this->country($country)['rules']['fallback_priority'] ?? [])
            ->map(fn ($code): string => strtoupper(trim((string) $code)))
            ->filter()->unique()->values()->all();
    }
}
