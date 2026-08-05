<?php

namespace App\Modules\Sync\Experience\Controllers;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\ManualSyncService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ManualSyncController
{
    public function __construct(private readonly ManualSyncService $sync) {}

    public function index(Request $request): Response
    {
        $orders = Order::query()->with(['address', 'manualSync.operator', 'shipment'])
            ->whereHas('manualSync', function ($query) use ($request): void {
                $query->when($request->input('status'), fn ($statusQuery, string $status) => $statusQuery->where('website_sync_status', $status));
            })
            ->when($request->input('search'), function ($query, string $search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('internal_order_number', 'like', "%{$search}%")
                        ->orWhereHas('address', fn ($address) => $address->where('recipient_name', 'like', "%{$search}%"));
                });
            })->latest('updated_at')->get();

        return Inertia::render('Sync/Index', [
            'orders' => $orders->map(fn (Order $order): array => [
                'id' => $order->id, 'internal_order_number' => $order->internal_order_number,
                'recipient_name' => $order->address?->recipient_name,
                'status' => $order->manualSync?->website_sync_status->value,
                'synced_at' => $order->manualSync?->synced_at?->toIso8601String(),
                'synced_by' => $order->manualSync?->synced_by,
                'tracking_number' => $order->shipment?->tracking_number,
                'source_type' => $order->source_type->value,
            ])->values()->all(),
            'filters' => $request->only(['status', 'search']),
        ]);
    }

    public function payload(Order $order): \Illuminate\Http\JsonResponse
    {
        $order->load(['address', 'items', 'shipment']);

        return response()->json([
            'order_number' => $order->internal_order_number,
            'source_type' => $order->source_type->value,
            'order_date' => $order->order_date?->toDateString(),
            'recipient' => $order->address?->only(['recipient_name', 'phone', 'country', 'province_state', 'city', 'address_line_1', 'address_line_2', 'postal_code']),
            'items' => $order->items->map(fn ($item): array => $item->only(['product_summary', 'sku', 'quantity', 'unit_price']))->values(),
            'carrier' => $order->shipment?->carrier,
            'tracking_number' => $order->shipment?->tracking_number,
            'shipped_at' => $order->shipment?->shipped_at?->toIso8601String(),
        ]);
    }

    public function complete(Request $request, Order $order): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate(['sync_note' => ['nullable', 'string', 'max:5000']]);
        $this->sync->complete($order, $request->user(), $data['sync_note'] ?? null);

        return back()->with('success', '人工同步已完成');
    }

    public function reopen(Request $request, Order $order): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate(['sync_note' => ['nullable', 'string', 'max:5000']]);
        $this->sync->reopen($order, $request->user(), $data['sync_note'] ?? null);

        return back()->with('success', '人工同步已重开');
    }

    public function return(Request $request, Order $order): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate(['sync_note' => ['nullable', 'string', 'max:5000']]);
        $this->sync->returnForCorrection($order, $request->user(), $data['sync_note'] ?? null);

        return back()->with('success', '人工同步已退回更正');
    }
}
