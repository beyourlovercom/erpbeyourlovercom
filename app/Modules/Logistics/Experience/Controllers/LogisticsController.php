<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class LogisticsController
{
    private const PER_PAGE = 50;

    public function __construct(private readonly ShipmentService $shipments) {}

    public function index(Request $request): Response
    {
        $orders = Order::query()->with(['address', 'items', 'owner', 'shipment'])
            ->where('order_status', 'pending_shipment')->latest('order_date')->latest('id')
            ->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('Logistics/Index', [
            'orders' => $orders->through(fn (Order $order): array => $this->data($order))->toArray(),
        ]);
    }

    public function markShipped(Request $request, Order $order): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'carrier' => ['required', 'string', 'max:128'],
            'tracking_number' => ['required', 'string', 'max:128'],
            'shipped_at' => ['required', 'date'],
            'logistics_note' => ['nullable', 'string', 'max:5000'],
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
        $order->load(['address', 'items', 'shipment']);

        return response()->json([
            'order_number' => $order->external_order_number,
            'recipient_name' => $order->address?->recipient_name,
            'phone' => $order->address?->phone,
            'country' => $order->address?->country,
            'province_state' => $order->address?->province_state,
            'city' => $order->address?->city,
            'address_line_1' => $order->address?->address_line_1,
            'address_line_2' => $order->address?->address_line_2,
            'postal_code' => $order->address?->postal_code,
            'items' => $order->items->map(fn ($item): array => $item->only(['product_summary', 'sku', 'quantity', 'unit_price']))->values(),
            'carrier' => $order->shipment?->carrier,
            'tracking_number' => $order->shipment?->tracking_number,
            'shipped_at' => $order->shipment?->shipped_at?->toIso8601String(),
        ]);
    }

    private function data(Order $order): array
    {
        return [
            'id' => $order->id, 'external_order_number' => $order->external_order_number,
            'internal_order_number' => $order->internal_order_number,
            'order_kind' => $order->order_kind?->value,
            'order_kind_label' => match ($order->order_kind) {
                \App\Modules\Orders\Application\OrderKind::Customer => '客户订单',
                \App\Modules\Orders\Application\OrderKind::KolFulfillment => '网红履约订单',
                null => '未分类',
            },
            'recipient_name' => $order->address?->recipient_name, 'phone' => $order->address?->phone,
            'country' => $order->address?->country,
            'owner_label' => $order->owner ? 'Owner('.$order->owner->name.')' : null,
            'item_count' => $order->items->sum('quantity'),
            'shipment' => $order->shipment?->only(['carrier', 'tracking_number', 'shipped_at', 'logistics_note']),
        ];
    }
}
