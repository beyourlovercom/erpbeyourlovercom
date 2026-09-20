<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Logistics\Infrastructure\Models\Carrier;
use App\Modules\Logistics\Infrastructure\Models\CarrierService;
use Illuminate\Support\Collection;

final class CarrierServiceCatalog
{
    /** @return Collection<int,CarrierService> */
    public function activeServices(string $carrierName): Collection
    {
        $carrier = $this->carrier($carrierName);
        if (! $carrier) return collect();

        return $carrier->services()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function defaultService(string $carrierName, ?string $destinationCountry = null): ?CarrierService
    {
        $carrier = $this->carrier($carrierName);
        if (! $carrier) return null;

        $country = strtoupper(trim((string) $destinationCountry));
        if (strcasecmp($carrier->name, 'DHL') === 0 && $country === 'DE') {
            $domestic = $carrier->services()->where('service_key', 'paket_domestic')->where('is_active', true)->first();
            if ($domestic) return $domestic;
        }

        if ($carrier->default_service_id) {
            $default = $carrier->services()->whereKey($carrier->default_service_id)->where('is_active', true)->first();
            if ($default) return $default;
        }

        return $carrier->services()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->first();
    }

    public function resolve(string $carrierName, ?string $serviceValue, ?string $destinationCountry = null): ?CarrierService
    {
        $carrier = $this->carrier($carrierName);
        if (! $carrier) return null;

        $serviceValue = trim((string) $serviceValue);
        if ($serviceValue !== '') {
            return $carrier->services()
                ->where('is_active', true)
                ->where(function ($query) use ($serviceValue): void {
                    $query->where('service_value', $serviceValue)
                        ->orWhere('service_key', $serviceValue)
                        ->orWhere('name_en', $serviceValue)
                        ->orWhere('name_zh', $serviceValue);
                })
                ->first();
        }

        return $this->defaultService($carrierName, $destinationCountry);
    }

    public function carrierDisplayName(string $carrierName): string
    {
        $carrier = $this->carrier($carrierName);
        if (! $carrier) return trim($carrierName);
        return $carrier->displayName();
    }

    private function carrier(string $carrierName): ?Carrier
    {
        $carrierName = trim($carrierName);
        if ($carrierName === '') return null;
        return Carrier::query()->where('name', $carrierName)->where('is_active', true)->first();
    }
}
