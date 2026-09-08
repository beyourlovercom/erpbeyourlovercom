<?php

namespace App\Modules\Logistics\Application;

final class CarrierRoutingPolicy
{
    public function displayName(string $carrier): string
    {
        $carrier = trim($carrier);
        return (string) config("carrier_routing.labels.{$carrier}", $carrier);
    }

    /** @return array<string,mixed> */
    public function factoryRoute(?string $country): array
    {
        $country = strtoupper(trim((string) $country));
        $default = config('carrier_routing.factory_fallback', []);
        $override = $country !== '' ? config("carrier_routing.factory_country_overrides.{$country}") : null;
        if (! is_array($default)) $default = [];
        return is_array($override) ? array_replace($default, $override) : $default;
    }
}
