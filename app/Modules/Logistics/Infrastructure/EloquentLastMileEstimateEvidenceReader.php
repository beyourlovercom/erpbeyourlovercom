<?php

namespace App\Modules\Logistics\Infrastructure;

use App\Modules\Logistics\Application\CarrierRoutingPolicy;
use App\Modules\Logistics\Application\Contracts\LastMileEstimateEvidenceReader;
use App\Modules\Logistics\Application\FulfillmentPlanCostEstimator;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\DB;

final class EloquentLastMileEstimateEvidenceReader implements LastMileEstimateEvidenceReader
{
    public function __construct(
        private readonly FulfillmentPlanCostEstimator $estimator,
        private readonly CarrierRoutingPolicy $carrierRouting,
    ) {}

    public function read(int|string $orderId): ?array
    {
        $order = Order::query()->with(['address', 'items', 'shipment.warehouse', 'fulfillmentAllocation'])->find($orderId);
        if ($order === null) return null;

        $country = strtoupper(trim((string) $order->address?->country));
        if ($country === '') return ['blocked' => 'last_mile_destination_country_missing'];

        $selected = $this->selectedSegments($order, $country);
        if (isset($selected['blocked'])) return $selected;
        $segments = $selected['segments'];
        $productIds = collect($segments)->flatMap(fn (array $segment) => collect($segment['items'] ?? [])->pluck('product_id'))
            ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($productIds === []) return ['blocked' => 'last_mile_product_identity_missing'];

        $context = $this->estimator->prepare($productIds);
        $result = $this->estimator->estimateRoutingOnly($country, $segments, [
            'province_state' => $order->address?->province_state,
            'state' => $order->address?->province_state,
            'city' => $order->address?->city,
            'postal_code' => $order->address?->postal_code,
            'zip' => $order->address?->postal_code,
        ], $context);

        if (($result['status'] ?? null) !== 'ready') {
            return ['blocked' => (string) ($result['reason_code'] ?? 'last_mile_estimate_unavailable'), 'estimate' => $result];
        }

        return [
            'amount' => number_format((float) $result['amount'], 4, '.', ''),
            'currency' => 'CNY',
            'data_source' => 'selected_fulfillment_rate_estimate',
            'source_at' => $selected['source_at'] ?? null,
            'business_evidence_at' => $this->businessEvidenceAt($order, $selected, $productIds),
            'business_evidence_fingerprint' => $this->businessEvidenceFingerprint($order, $selected, $segments, $result),
            'selected_source' => $selected['selected_source'] ?? null,
            'estimate' => $result,
        ];
    }

    private function businessEvidenceAt(Order $order, array $selected, array $productIds): ?string
    {
        $values = [$selected['source_at'] ?? null, $order->address?->updated_at];

        if ($productIds !== []) {
            $packaging = DB::table('product_packaging_profiles as profile')
                ->join('packaging_measurement_records as measurement', 'measurement.id', '=', 'profile.current_measurement_record_id')
                ->where('profile.is_active', true)
                ->where('measurement.status', 'approved')
                ->whereColumn('measurement.product_id', 'profile.product_id')
                ->whereIn('profile.product_id', $productIds);
            $values[] = (clone $packaging)->max('profile.updated_at');
            $values[] = (clone $packaging)->max('measurement.updated_at');

            $values[] = DB::table('products as product')
                ->join('product_shipping_weight_standard_candidates as candidate', 'candidate.id', '=', 'product.shipping_weight_standard_candidate_id')
                ->whereIn('product.id', $productIds)
                ->where('candidate.workflow_status', 'adopted')
                ->whereColumn('candidate.product_id', 'product.id')
                ->max('candidate.updated_at');

            $values[] = DB::table('products as product')
                ->join('product_weight_evidences as evidence', 'evidence.id', '=', 'product.shipping_weight_evidence_id')
                ->whereIn('product.id', $productIds)
                ->where('evidence.status', 'adopted')
                ->where('evidence.weight_kind', 'shipping_reference_weight')
                ->whereColumn('evidence.product_id', 'product.id')
                ->max('evidence.updated_at');
        }

        $timestamps = collect($values)->filter()->map(fn ($value): int => strtotime((string) $value))->filter(fn (int $value): bool => $value > 0);
        if ($timestamps->isEmpty()) return null;

        return gmdate('Y-m-d H:i:s', (int) $timestamps->max());
    }

    private function businessEvidenceFingerprint(Order $order, array $selected, array $segments, array $result): string
    {
        $stableSegments = collect($segments)->map(fn (array $segment): array => [
            'source_type' => $segment['source_type'] ?? null,
            'source_code' => $segment['source_code'] ?? null,
            'warehouse_id' => $segment['warehouse_id'] ?? null,
            'carrier' => $segment['carrier'] ?? null,
            'service' => $segment['service'] ?? null,
            'items' => $segment['items'] ?? [],
        ])->values()->all();

        $payload = [
            'destination' => [
                'country' => strtoupper(trim((string) $order->address?->country)),
                'province_state' => $order->address?->province_state,
                'city' => $order->address?->city,
                'postal_code' => $order->address?->postal_code,
            ],
            'selected_source' => $selected['selected_source'] ?? null,
            'segments' => $stableSegments,
            'routing_components' => $result['components'] ?? [],
            'routing_segments' => $result['segments'] ?? [],
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @return array<string,mixed> */
    private function selectedSegments(Order $order, string $country): array
    {
        $shipment = $order->shipment;
        if ($shipment?->warehouse) {
            $items = $this->orderItems($order);
            if (isset($items['blocked'])) return $items;
            $code = strtoupper(trim((string) $shipment->warehouse->code));
            $segment = [
                'source_type' => $code === 'FACTORY' ? 'factory' : 'warehouse',
                'source_code' => $code,
                'warehouse_id' => (int) $shipment->warehouse_id,
                'warehouse_name' => (string) $shipment->warehouse->name,
                'carrier' => $shipment->carrier,
                'service' => $shipment->service,
                'items' => $items['items'],
            ];
            $segment = $this->enrichFactory($segment, $country);
            return ['segments' => [$segment], 'source_at' => $shipment->updated_at, 'selected_source' => 'shipment'];
        }

        $allocation = $order->fulfillmentAllocation;
        if ($allocation && $allocation->status === 'submitted' && is_array($allocation->plan_snapshot)) {
            $plan = $allocation->plan_snapshot;
            if (blank($allocation->selected_plan_key) || (string) ($plan['plan_key'] ?? '') !== (string) $allocation->selected_plan_key) {
                return ['blocked' => 'last_mile_allocation_snapshot_mismatch'];
            }
            $segments = collect($plan['segments'] ?? [])->map(fn (array $segment): array => $this->enrichFactory($segment, $country))->values()->all();
            if ($segments === []) return ['blocked' => 'last_mile_selected_fulfillment_missing'];
            foreach ($segments as $segment) {
                foreach ($segment['items'] ?? [] as $item) {
                    if (empty($item['product_id']) || (int) ($item['quantity'] ?? 0) <= 0) return ['blocked' => 'last_mile_product_identity_missing'];
                }
            }
            return ['segments' => $segments, 'source_at' => $allocation->submitted_at ?? $allocation->updated_at, 'selected_source' => 'fulfillment_allocation'];
        }

        return ['blocked' => 'last_mile_selected_fulfillment_missing'];
    }

    /** @return array<string,mixed> */
    private function orderItems(Order $order): array
    {
        $items = [];
        foreach ($order->items as $item) {
            if ($item->product_id === null || (int) $item->quantity <= 0) return ['blocked' => 'last_mile_product_identity_missing'];
            $items[] = ['product_id' => (int) $item->product_id, 'quantity' => (int) $item->quantity];
        }
        return $items === [] ? ['blocked' => 'last_mile_order_items_missing'] : ['items' => $items];
    }

    private function enrichFactory(array $segment, string $country): array
    {
        if (($segment['source_type'] ?? null) !== 'factory' && strtoupper((string) ($segment['source_code'] ?? '')) !== 'FACTORY') return $segment;
        $route = $this->carrierRouting->factoryRoute($country);
        if (blank($segment['carrier'] ?? null)) $segment['carrier'] = $route['carrier'] ?? null;
        if (! array_key_exists('service', $segment) || blank($segment['service'])) $segment['service'] = $route['service'] ?? null;
        $segment['source_type'] = 'factory';
        $segment['source_code'] = 'FACTORY';
        return $segment;
    }
}
