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
            $needle = mb_strtolower($serviceValue);
            return $carrier->services()
                ->where('is_active', true)
                ->get()
                ->first(function (CarrierService $service) use ($needle): bool {
                    foreach ([$service->service_value, $service->service_key, $service->name_en, $service->name_zh] as $candidate) {
                        if ($candidate !== null && mb_strtolower(trim((string) $candidate)) === $needle) return true;
                    }
                    return false;
                });
        }

        return $this->defaultService($carrierName, $destinationCountry);
    }

    /** @return list<array<string,mixed>> */
    public function options(): array
    {
        return Carrier::query()
            ->with(['services' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('id'), 'defaultService'])
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->map(function (Carrier $carrier): array {
                $default = $carrier->defaultService && $carrier->defaultService->is_active
                    ? $carrier->defaultService
                    : $carrier->services->first();
                return [
                    'value' => $carrier->name,
                    'label' => $carrier->displayName(),
                    'default_service_id' => $default?->id ? (int) $default->id : null,
                    'default_service_value' => $default?->service_value,
                    'services' => $carrier->services->map(fn (CarrierService $service): array => [
                        'id' => (int) $service->id,
                        'key' => $service->service_key,
                        'value' => $service->service_value,
                        'label' => $service->displayName(),
                        'name_en' => $service->name_en,
                        'name_zh' => $service->name_zh,
                    ])->values()->all(),
                ];
            })->values()->all();
    }

    public function serviceDisplayName(string $carrierName, ?string $serviceValue): ?string
    {
        $serviceValue = trim((string) $serviceValue);
        if ($serviceValue === '') return null;
        $service = $this->resolve($carrierName, $serviceValue);
        return $service?->displayName() ?: $serviceValue;
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
