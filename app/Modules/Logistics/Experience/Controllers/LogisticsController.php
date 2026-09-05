<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Logistics\Application\WarehouseRecommendationService;
use App\Modules\Logistics\Infrastructure\Models\Carrier;
use App\Modules\Orders\Application\OrderPackagingReferenceProjection;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class LogisticsController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly InventoryAvailabilityReader $availability,
        private readonly WarehouseRecommendationService $recommendations,
        private readonly OrderPackagingReferenceProjection $packagingReferences,
    ) {}

    public function index(Request $request): Response
    {
        $orders = Order::query()->with(['address', 'items', 'owner', 'shipment.warehouse'])
            ->where('order_status', 'pending_shipment')->latest('order_date')->latest('id')
            ->paginate(self::PER_PAGE)->withQueryString();

        $productIds = collect($orders->items())->flatMap(fn (Order $order) => $order->items->pluck('product_id'))->filter()->unique()->values()->all();
        $availability = $this->availability->forProductIds($productIds);

        return Inertia::render('Logistics/Index', [
            'orders' => $orders->through(fn (Order $order): array => $this->data($order, $availability))->toArray(),
            'warehouses' => $this->availability->activeWarehouses(),
            'carrierOptions' => Carrier::query()->where('is_active', true)->orderBy('id')->get(['name'])->map(fn (Carrier $carrier): array => [
                'value' => $carrier->name,
                'label' => $carrier->name,
            ])->values()->all(),
        ]);
    }

    public function saveDraft(Request $request, Order $order): \Illuminate\Http\RedirectResponse
    {
        $this->shipments->saveDraft($order, $request->validate($this->draftRules()), $request->user());

        return back()->with('success', '履约草稿已保存');
    }

    public function markShipped(Request $request, Order $order): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            ...$this->draftRules(),
            'carrier' => ['required', 'string', 'max:128', Rule::exists('logistics_carriers', 'name')->where('is_active', true)],
            'tracking_number' => ['required', 'string', 'max:128'],
            'shipped_at' => ['required', 'date'],
        ]);
        $this->shipments->markShipped($order, $data, $request->user());

        return back()->with('success', '已标记发货，已生成待同步任务');
    }

    public function reopen(Request $request, Order $order): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate(['logistics_note' => ['nullable', 'string', 'max:5000']]);
        $this->shipments->reopen($order, $request->user(), $data['logistics_note'] ?? null);

        return back()->with('success', '发货已重开，可修正后重新发货');
    }

    public function payload(Order $order): \Illuminate\Http\JsonResponse
    {
        $order->load(['address', 'items', 'shipment.warehouse']);
        $packaging = $this->packagingReferences->forOrder($order);

        return response()->json([
            'order_number' => $order->external_order_number,
            'erp_order_id' => $order->erp_order_id,
            'recipient_name' => $order->address?->recipient_name,
            'phone' => $order->address?->phone,
            'country' => $order->address?->country,
            'province_state' => $order->address?->province_state,
            'city' => $order->address?->city,
            'address_line_1' => $order->address?->address_line_1,
            'address_line_2' => $order->address?->address_line_2,
            'postal_code' => $order->address?->postal_code,
            'items' => $order->items->map(fn ($item): array => [
                ...$item->only(['product_summary', 'sku', 'quantity', 'unit_price']),
                'packaging_reference' => $packaging[(int) $item->id] ?? null,
            ])->values(),
            'warehouse' => $order->shipment?->warehouse?->name,
            'carrier' => $order->shipment?->carrier,
            'service' => $order->shipment?->service,
            'tracking_number' => $order->shipment?->tracking_number,
            'shipped_at' => $order->shipment?->shipped_at?->toIso8601String(),
        ]);
    }

    private function draftRules(): array
    {
        return [
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'carrier' => ['nullable', 'string', 'max:128', Rule::exists('logistics_carriers', 'name')->where('is_active', true)],
            'service' => ['nullable', 'string', 'max:128'],
            'tracking_number' => ['nullable', 'string', 'max:128'],
            'shipped_at' => ['nullable', 'date'],
            'logistics_note' => ['nullable', 'string', 'max:5000'],
        ];
    }

    private function data(Order $order, array $availability): array
    {
        return [
            'id' => $order->id,
            'external_order_number' => $order->external_order_number,
            'erp_order_id' => $order->erp_order_id,
            'internal_order_number' => $order->internal_order_number,
            'order_kind' => $order->order_kind?->value,
            'order_kind_label' => match ($order->order_kind) {
                \App\Modules\Orders\Application\OrderKind::Customer => '客户订单',
                \App\Modules\Orders\Application\OrderKind::KolFulfillment => '网红履约订单',
                null => '未分类',
            },
            'recipient_name' => $order->address?->recipient_name,
            'phone' => $order->address?->phone,
            'country' => $order->address?->country,
            'owner_label' => $order->owner ? 'Owner('.$order->owner->name.')' : null,
            'item_count' => $order->items->sum('quantity'),
            'items' => $order->items->map(fn ($item): array => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'sku' => $item->sku,
                'product_summary' => $item->product_summary,
                'quantity' => $item->quantity,
                'availability' => $availability[$item->product_id] ?? [],
            ])->values()->all(),
            'warehouse_recommendation' => $this->recommendations->recommend(
                $order->address?->country,
                $order->items->map(fn ($item): array => [
                    'product_id' => $item->product_id,
                    'quantity' => (int) $item->quantity,
                ])->values()->all(),
            ),
            'shipment' => $order->shipment ? [
                ...$order->shipment->only(['warehouse_id', 'warehouse_selection_source', 'carrier', 'service', 'carrier_selection_source', 'tracking_number', 'shipped_at', 'logistics_note']),
                'warehouse_name' => $order->shipment->warehouse?->name,
            ] : null,
        ];
    }
}
