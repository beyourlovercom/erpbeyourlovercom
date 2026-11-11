<?php

namespace App\Modules\Logistics\Application\Domestic;

use App\Modules\Logistics\Application\CarrierRoutingPolicy;
use App\Modules\Logistics\Application\CarrierServiceCatalog;
use App\Modules\Orders\Infrastructure\Models\Order;

final class DomesticCarrierDefaults
{
    public function __construct(
        private readonly CarrierRoutingPolicy $routing,
        private readonly CarrierServiceCatalog $catalog,
    ) {}

    /** @return array{carrier:string,service:string,carrier_service_id:int}|null */
    public function recommended(Order $order): ?array
    {
        $country = strtoupper(trim((string) $order->address?->country));
        if (! preg_match('/^[A-Z]{2}$/', $country)) {
            return null;
        }

        $route = $this->routing->factoryRoute($country);
        $carrier = trim((string) ($route['carrier'] ?? ''));
        $service = trim((string) ($route['service'] ?? ''));
        $matched = $this->catalog->activeServices($carrier)->firstWhere('service_value', $service);
        if ($carrier === '' || $service === '' || ! $matched) {
            return null;
        }

        return ['carrier' => $carrier, 'service' => $service, 'carrier_service_id' => (int) $matched->id];
    }

    public function ready(Order $order): bool
    {
        $shipment = $order->shipment;
        if (! $shipment || ! in_array($shipment->carrier_selection_source, ['manual', 'automatic'], true)) {
            return false;
        }
        $carrier = trim((string) $shipment->carrier);
        $service = trim((string) $shipment->service);
        if ($carrier === '' || $service === ''
            || ! $this->catalog->activeServices($carrier)->contains('service_value', $service)) {
            return false;
        }
        if ($shipment->carrier_selection_source === 'manual') {
            return true;
        }

        // An automatic value is trusted only while it matches the active
        // country routing policy, not merely because it was once persisted.
        $route = $this->recommended($order);
        return $route !== null
            && strcasecmp($carrier, $route['carrier']) === 0
            && $service === $route['service'];
    }

    /** Idempotent; never overwrites a staff-selected carrier/service. */
    public function fill(Order $order): bool
    {
        $shipment = $order->shipment;
        if (! $shipment || $shipment->shipment_status !== 'pending'
            || $shipment->carrier_selection_source === 'manual'
            || filled($shipment->tracking_number) || $shipment->shipped_at !== null) {
            return false;
        }

        $route = $this->recommended($order);
        $values = $route === null
            ? ['carrier' => null, 'service' => null, 'carrier_service_id' => null, 'carrier_selection_source' => null]
            : [...$route, 'carrier_selection_source' => 'automatic'];
        $shipment->forceFill($values);
        if (! $shipment->isDirty()) {
            return false;
        }
        $shipment->save();
        return true;
    }
}
