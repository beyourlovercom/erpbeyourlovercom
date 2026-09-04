<?php

namespace App\Modules\Procurement\Experience\Controllers;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Procurement\Application\ProcurementOrderService;
use App\Modules\Procurement\Infrastructure\Models\ProcurementOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ProcurementOrderController
{
    public function __construct(private readonly ProcurementOrderService $service) {}

    public function index(): Response
    {
        $orders = ProcurementOrder::query()
            ->with('sourceOrder:id,external_order_number')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ProcurementOrder $order): array => [
                'id' => $order->id,
                'procurement_number' => $order->procurement_number,
                'status' => $order->status,
                'source_order_id' => $order->source_order_id,
                'source_order_number' => $order->sourceOrder?->external_order_number ?? '—',
                'created_at' => $order->created_at?->toIso8601String(),
            ])->values()->all();

        return Inertia::render('Procurement/Orders/Index', ['orders' => $orders]);
    }

    public function create(Request $request, Order $order): Response
    {
        $order->load('items');
        abort_unless($order->operationalMarkers()->where('marker', 'stockout')->exists(), 404);
        abort_if(ProcurementOrder::query()->where('source_order_id', $order->id)->exists(), 409);
        return Inertia::render('Procurement/Orders/Create', ['order' => $this->orderData($order)]);
    }

    public function legacyCreate(Order $order): RedirectResponse
    {
        return redirect()->route('procurement.orders.create', $order);
    }

    public function store(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['required', 'array'],
            'items.*.purchase_url' => ['nullable', 'url', 'max:2048'],
            'items.*.purchase_size' => ['nullable', 'string', 'max:255'],
            'items.*.purchase_quantity' => ['required', 'integer', 'min:1'],
        ]);
        $order->loadMissing('items');
        $sourceItemIds = $order->items->modelKeys();
        $submittedItemIds = array_map('intval', array_keys($data['items']));
        abort_unless(
            count($submittedItemIds) === count($sourceItemIds)
                && ! array_diff($sourceItemIds, $submittedItemIds)
                && ! array_diff($submittedItemIds, $sourceItemIds),
            422,
            '采购明细必须完整对应源订单商品。',
        );
        $items = collect($data['items'])->mapWithKeys(fn (array $item, $id) => [(int) $id => $item])->all();
        $procurement = $this->service->createFromOrder($order, $request->user(), $items);
        return redirect()->route('procurement.orders.show', $procurement)->with('success', '采购订单已创建');
    }

    public function show(Request $request, ProcurementOrder $procurementOrder): Response
    {
        abort_unless($request->user()->can('procurement.view'), 403);
        $procurementOrder->load('items', 'sourceOrder');
        return Inertia::render('Procurement/Orders/Show', [
            'procurementOrder' => $procurementOrder->only(['id', 'procurement_number', 'status', 'source_order_id', 'created_at']) + [
                'source_order_number' => $procurementOrder->sourceOrder->external_order_number,
                'items' => $procurementOrder->items->map(fn ($item) => $item->only(['id', 'sku', 'product_summary', 'source_quantity', 'purchase_url', 'purchase_size', 'purchase_quantity']))->all(),
            ],
        ]);
    }

    public function legacyShow(ProcurementOrder $procurementOrder): RedirectResponse
    {
        return redirect()->route('procurement.orders.show', $procurementOrder);
    }

    private function orderData(Order $order): array
    {
        return [
            'id' => $order->id,
            'external_order_number' => $order->external_order_number,
            'order_status' => $order->order_status->value,
            'items' => $order->items->map(fn ($item) => $item->only(['id', 'product_id', 'sku', 'product_summary', 'quantity']))->values()->all(),
        ];
    }
}
