<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Logistics\Application\CarrierRoutingPolicy;
use App\Modules\Logistics\Infrastructure\Models\Carrier;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Models\LogisticsReview;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

final class CarrierController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly CarrierRoutingPolicy $carrierRouting,
    ) {}

    public function index(Request $request): Response
    {
        $masters = Carrier::query()->orderBy('id')->get();
        $shipments = Shipment::query()
            ->with('billingEvidence')
            ->whereHas('order', fn ($q) => $q->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))
            ->whereIn('carrier', $masters->pluck('name'))
            ->get()
            ->groupBy(fn (Shipment $shipment): string => trim((string) $shipment->carrier));

        $carriers = $masters->map(fn (Carrier $carrier): array => [
            'id' => $carrier->id,
            'name' => $carrier->name,
            'display_name' => $this->carrierRouting->displayName($carrier->name),
            'is_active' => $carrier->is_active,
            ...$this->summary($shipments->get($carrier->name, collect())),
        ])->values();

        return Inertia::render('Logistics/Carriers/Index', [
            'carriers' => $carriers,
            'canOperate' => (bool) $request->user()?->can('logistics.operate'),
            'statusBar' => $this->statusBar(),
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
            ->whereHas('order', fn ($q) => $q->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))
            ->where('carrier', $carrier)
            ->get();

        $shipments = Shipment::query()
            ->with(['order.address', 'order.items.product', 'warehouse', 'handler', 'billingEvidence'])
            ->whereHas('order', fn ($q) => $q->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))
            ->where('carrier', $carrier)
            ->latest('shipped_at')
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('Logistics/Carriers/Show', [
            'carrier' => $carrier,
            'carrierDisplayName' => $this->carrierRouting->displayName($carrier),
            'summary' => $this->summary($summaryRows),
            'shipments' => $shipments->through(fn (Shipment $shipment): array => $this->ledgerRow($shipment))->toArray(),
            'statusBar' => $this->statusBar(),
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
            'erp_order_id' => $order?->erp_order_id,
            'order_kind' => $order?->order_kind?->value,
            'order_url' => $this->orderUrl($order),
            'warehouse_name' => $shipment->warehouse?->name,
            'destination_country' => $order?->address?->country,
            'carrier' => $shipment->carrier,
            'service' => $shipment->service,
            'tracking_number' => $shipment->tracking_number,
            'items' => $order?->items->map(fn ($item): array => [
                'sku' => $item->resolved_fulfillment_sku ?: $item->sku,
                'source_sku' => $item->source_sku ?: $item->sku,
                'product_summary' => $item->product_summary,
                'product_id' => $item->product_id,
                'product_url' => $item->product ? "/products/{$item->product_id}" : null,
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

    private function orderUrl(?Order $order): ?string
    {
        if (! $order || $order->order_kind?->value === 'fulfillment_derived') {
            return null;
        }

        return $order->order_kind?->value === 'kol_fulfillment'
            ? "/kol/orders/{$order->id}"
            : "/customers/orders/{$order->id}";
    }

    private function statusBar(): array
    {
        return [
            ['key' => 'pending', 'label' => '待发货', 'url' => '/logistics/pending', 'count' => Order::query()->where('order_status', 'pending_shipment')->whereDoesntHave('externalReferences', fn ($q) => $q->where('provider', 'historical_logistics'))->whereDoesntHave('logisticsReview', fn ($q) => $q->where('status', 'pending'))->count()],
            ['key' => 'awaiting', 'label' => '已发货', 'url' => '/logistics/awaiting-tracking', 'count' => Shipment::query()->where('shipment_status', 'shipped')->whereNotNull('shipped_at')->where(fn ($q) => $q->whereNull('tracking_number')->orWhere('tracking_number', ''))->whereHas('order', fn ($q) => $q->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))->count()],
            ['key' => 'transit', 'label' => '运输中', 'url' => '/logistics/in-transit', 'count' => Shipment::query()->where('shipment_status', 'shipped')->whereNotNull('tracking_number')->where('tracking_number', '!=', '')->whereHas('order', fn ($q) => $q->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))->count()],
            ['key' => 'exception', 'label' => '异常单', 'url' => '/logistics/exceptions', 'count' => Shipment::query()->where('shipment_status', 'exception')->whereHas('order', fn ($q) => $q->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))->count()],
            ['key' => 'review', 'label' => '复核单', 'url' => '/logistics/review', 'count' => LogisticsReview::query()->where('status', 'pending')->whereHas('order', fn ($q) => $q->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))->count()],
            ['key' => 'completed', 'label' => '已完结', 'url' => '/logistics/completed', 'count' => Shipment::query()->where('shipment_status', 'completed')->whereHas('order', fn ($q) => $q->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))->count()],
            ['key' => 'cancelled', 'label' => '已取消', 'url' => '/logistics/cancelled', 'count' => Order::query()->where('order_status', 'cancelled')->whereDoesntHave('externalReferences', fn ($q) => $q->where('provider', 'historical_logistics'))->count()],
        ];
    }
}
