<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Logistics\Infrastructure\Models\Carrier;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

final class CarrierController
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $masters = Carrier::query()->orderBy('id')->get();
        $shipments = Shipment::query()
            ->with('billingEvidence')
            ->whereIn('carrier', $masters->pluck('name'))
            ->get()
            ->groupBy(fn (Shipment $shipment): string => trim((string) $shipment->carrier));

        $carriers = $masters->map(fn (Carrier $carrier): array => [
            'id' => $carrier->id,
            'name' => $carrier->name,
            'is_active' => $carrier->is_active,
            ...$this->summary($shipments->get($carrier->name, collect())),
        ])->values();

        return Inertia::render('Logistics/Carriers/Index', [
            'carriers' => $carriers,
            'canOperate' => (bool) $request->user()?->can('logistics.operate'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128', 'unique:logistics_carriers,name'],
        ]);

        Carrier::query()->create([
            'name' => $validated['name'],
            'is_active' => true,
            'created_by' => $request->user()?->getAuthIdentifier(),
        ]);

        return redirect()->route('logistics.carriers')->with('success', '承运商已新增');
    }

    public function show(Request $request, string $carrier): Response
    {
        $carrier = trim($carrier);
        abort_if($carrier === '', 404);
        Carrier::query()->where('name', $carrier)->firstOrFail();

        $summaryRows = Shipment::query()
            ->with('billingEvidence')
            ->where('carrier', $carrier)
            ->get();

        $shipments = Shipment::query()
            ->with(['order.address', 'order.items', 'warehouse', 'handler', 'billingEvidence'])
            ->where('carrier', $carrier)
            ->latest('shipped_at')
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('Logistics/Carriers/Show', [
            'carrier' => $carrier,
            'summary' => $this->summary($summaryRows),
            'shipments' => $shipments->through(fn (Shipment $shipment): array => $this->ledgerRow($shipment))->toArray(),
        ]);
    }

    private function summary(Collection $shipments): array
    {
        $costs = [];
        $weight = 0.0;
        $weightRows = 0;
        $deliveryCoverage = 0;
        $delivery = ['in_transit' => 0, 'delivered' => 0, 'exception' => 0];

        foreach ($shipments as $shipment) {
            $billing = $shipment->billingEvidence;
            if ($billing?->chargeable_weight_kg !== null) {
                $weight += (float) $billing->chargeable_weight_kg;
                $weightRows++;
            }
            if ($billing?->actual_cost !== null) {
                $currency = strtoupper(trim((string) $billing->currency)) ?: 'UNSPECIFIED';
                $costs[$currency] ??= ['currency' => $currency, 'total' => 0.0, 'shipments' => 0];
                $costs[$currency]['total'] += (float) $billing->actual_cost;
                $costs[$currency]['shipments']++;
            }
            $status = strtolower(trim((string) ($billing?->delivery_status ?? '')));
            if ($status !== '') {
                $deliveryCoverage++;
                if (array_key_exists($status, $delivery)) {
                    $delivery[$status]++;
                }
            }
        }

        $costs = collect($costs)->map(function (array $cost): array {
            $cost['total'] = round($cost['total'], 4);
            $cost['average'] = $cost['shipments'] > 0 ? round($cost['total'] / $cost['shipments'], 4) : null;
            return $cost;
        })->values()->all();

        $lastShipped = $shipments->pluck('shipped_at')->filter()->sortDesc()->first();
        $thisMonth = $shipments->filter(fn (Shipment $shipment): bool => $shipment->shipped_at?->gte(now()->startOfMonth()) ?? false)->count();

        return [
            'shipment_count' => $shipments->count(),
            'chargeable_weight_kg' => $weightRows > 0 ? round($weight, 3) : null,
            'chargeable_weight_coverage' => $weightRows,
            'costs_by_currency' => $costs,
            'this_month_count' => $thisMonth,
            'delivery_status_coverage' => $deliveryCoverage,
            'in_transit_count' => $delivery['in_transit'],
            'delivered_count' => $delivery['delivered'],
            'exception_count' => $delivery['exception'],
            'last_shipped_at' => $lastShipped?->toIso8601String(),
        ];
    }

    private function ledgerRow(Shipment $shipment): array
    {
        $order = $shipment->order;
        $billing = $shipment->billingEvidence;

        return [
            'id' => $shipment->id,
            'shipped_at' => $shipment->shipped_at?->toIso8601String(),
            'external_order_number' => $order?->external_order_number,
            'erp_order_id' => $order?->erp_order_id,
            'internal_order_number' => $order?->internal_order_number,
            'order_kind' => $order?->order_kind?->value,
            'warehouse_name' => $shipment->warehouse?->name,
            'destination_country' => $order?->address?->country,
            'carrier' => $shipment->carrier,
            'service' => $shipment->service,
            'tracking_number' => $shipment->tracking_number,
            'items' => $order?->items->map(fn ($item): array => [
                'sku' => $item->sku,
                'product_summary' => $item->product_summary,
                'quantity' => $item->quantity,
            ])->values()->all() ?? [],
            'chargeable_weight_kg' => $billing?->chargeable_weight_kg,
            'actual_cost' => $billing?->actual_cost,
            'currency' => $billing?->currency,
            'shipment_status' => $shipment->shipment_status,
            'delivery_status' => $billing?->delivery_status,
            'handler_name' => $shipment->handler?->name,
            'recipient_name' => $order?->address?->recipient_name,
            'billing_document_number' => $billing?->billing_document_number,
            'provider_reference' => $billing?->provider_reference,
            'data_source' => $billing?->data_source,
            'billed_at' => $billing?->billed_at?->toIso8601String(),
            'raw_evidence' => $billing?->raw_evidence,
        ];
    }
}
