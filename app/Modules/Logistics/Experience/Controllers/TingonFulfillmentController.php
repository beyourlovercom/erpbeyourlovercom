<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Logistics\Application\AssistedFulfillmentService;
use App\Modules\Logistics\Application\CarrierServiceCatalog;
use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Logistics\Application\ShipmentWebsiteSyncHandoff;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class TingonFulfillmentController
{
    private const WAREHOUSE = 'TINGON';

    public function index(Request $request, CarrierServiceCatalog $carrierCatalog): Response
    {
        $view = (string) $request->query('view', 'pending');
        if (! in_array($view, ['pending', 'all', 'brand', 'sent'], true)) $view = 'pending';
        $search = mb_substr(trim((string) $request->query('search', '')), 0, 120);
        $perPage = in_array((int) $request->query('per_page', 50), [20, 50, 100], true)
            ? (int) $request->query('per_page', 50) : 50;

        if ($view === 'brand') {
            $orders = Order::query()->eligibleForOperations()
                ->where('order_kind', '!=', 'fulfillment_derived')
                ->whereHas('items.product', static fn ($product) => $product->whereRaw(
                    "LOWER(TRIM(CASE WHEN brand_local_override = 1 THEN COALESCE(brand, '') ELSE COALESCE(NULLIF(woocommerce_brand, ''), brand, '') END)) = ?",
                    ['tingon']
                ))
                ->with(['address', 'items.product', 'shipment.warehouse'])
                ->when($search !== '', fn ($q) => $this->searchOrders($q, $search))
                ->orderByDesc('id')->paginate($perPage)->withQueryString();
            $rows = $orders->through(fn (Order $order): array => $this->orderRow($order))->toArray();
        } else {
            $query = Shipment::query()
                ->whereHas('warehouse', fn ($warehouse) => $warehouse->where('code', self::WAREHOUSE))
                ->whereHas('order', fn ($order) => $order->eligibleForOperations()->where('order_status', '!=', 'cancelled'))
                ->with(['warehouse', 'order.address', 'order.items.product', 'order.fulfillmentParent'])
                ->when($view === 'pending', fn ($q) => $q->where('shipment_status', 'pending'))
                ->when($view === 'sent', fn ($q) => $q->whereIn('shipment_status', ['shipped', 'completed']))
                ->when($search !== '', function ($q) use ($search): void {
                    $q->where(function ($nested) use ($search): void {
                        $nested->where('tracking_number', 'like', "%{$search}%")
                            ->orWhere('carrier', 'like', "%{$search}%")
                            ->orWhereHas('order', fn ($order) => $this->searchOrders($order, $search));
                    });
                });
            $shipments = $query->orderByDesc('id')->paginate($perPage)->withQueryString();
            $rows = $shipments->through(fn (Shipment $shipment): array => $this->shipmentRow($shipment))->toArray();
        }

        return Inertia::render('Logistics/TingonFulfillment', [
            'orders' => $rows,
            'view' => $view,
            'search' => $search,
            'canOperate' => $request->user()?->can('logistics.operate') ?? false,
            'carrierOptions' => $carrierCatalog->options(),
        ]);
    }

    public function save(Request $request, Order $order, ShipmentService $shipments): RedirectResponse
    {
        $shipment = $this->requireTingonShipment($order);
        $data = $request->validate([
            'carrier' => ['nullable', 'string', 'max:128', Rule::exists('logistics_carriers', 'name')->where('is_active', true)],
            'service' => ['nullable', 'string', 'max:128'],
            'tracking_number' => ['nullable', 'string', 'max:128'],
            'logistics_note' => ['nullable', 'string', 'max:5000'],
        ]);
        abort_unless($shipment->shipment_status === 'pending', 409, '只有待发货的合作仓订单可以保存草稿');
        $shipments->saveDraft($order, $data, $request->user());
        return back()->with('success', 'Tingon 发货信息已保存为 ERP 草稿，尚未确认实际发货');
    }

    public function ship(Request $request, Order $order, AssistedFulfillmentService $fulfillment, ShipmentWebsiteSyncHandoff $handoff): RedirectResponse
    {
        $shipment = $this->requireTingonShipment($order);
        abort_unless($shipment->shipment_status === 'pending', 409, '该合作仓订单已发货，不允许重复确认；修改单号请使用更正 Tracking');
        $data = $request->validate([
            'carrier' => ['required', 'string', 'max:128', Rule::exists('logistics_carriers', 'name')->where('is_active', true)],
            'service' => ['nullable', 'string', 'max:128'],
            'tracking_number' => ['required', 'string', 'max:128'],
            'logistics_note' => ['nullable', 'string', 'max:5000'],
        ]);
        $updated = $fulfillment->markShipped($order, [
            ...$data,
            'warehouse_id' => (int) $shipment->warehouse_id,
            'shipped_at' => $shipment->shipped_at ?? now(),
        ], $request->user());
        $handoff->afterShipmentSaved($updated);
        return back()->with('success', 'Tingon 发货已确认，Tracking 已保存；网站交给同步模块处理');
    }

    public function tracking(Request $request, Order $order, ShipmentService $shipments, ShipmentWebsiteSyncHandoff $handoff): RedirectResponse
    {
        $shipment = $this->requireTingonShipment($order);
        abort_unless(in_array($shipment->shipment_status, ['shipped', 'exception'], true), 409, '只有已发货的 Tingon 履约单可更正 Tracking');
        $data = $request->validate(['tracking_number' => ['required', 'string', 'max:128']]);
        $updated = $shipments->updateTracking($order, $data['tracking_number'], $request->user());
        $handoff->afterShipmentSaved($updated);
        return back()->with('success', 'Tingon Tracking 更正已保存，网站由同步模块核对');
    }

    private function requireTingonShipment(Order $order): Shipment
    {
        $order->loadMissing('shipment.warehouse');
        abort_unless(! $order->isOperationallyArchived()
            && $order->shipment?->warehouse?->code === self::WAREHOUSE, 409, '该订单没有分配 Tingon 合作仓');
        return $order->shipment;
    }

    private function searchOrders($query, string $term): void
    {
        $query->where(function ($nested) use ($term): void {
            $nested->where('erp_order_id', 'like', "%{$term}%")
                ->orWhere('external_order_number', 'like', "%{$term}%")
                ->orWhereHas('address', fn ($address) => $address->where('recipient_name', 'like', "%{$term}%"))
                ->orWhereHas('items', fn ($items) => $items->where('sku', 'like', "%{$term}%"));
        });
    }

    private function orderRow(Order $order): array
    {
        $shipment = $order->shipment;
        return [
            'id' => (int) $order->id,
            'order_id' => (int) $order->id,
            'order_number' => $order->erp_order_id ?: $order->external_order_number,
            'order_url' => '/orders/'.$order->id,
            'recipient_name' => $order->address?->recipient_name,
            'country' => $order->address?->country,
            'sku' => $order->items->map(fn ($item) => ($item->source_sku ?: $item->sku).' × '.(int) $item->quantity)->implode('、'),
            'warehouse' => $shipment?->warehouse?->name ?? '待分仓 / 多仓',
            'carrier' => $shipment?->carrier,
            'service' => $shipment?->service,
            'tracking_number' => $shipment?->tracking_number,
            'logistics_note' => $shipment?->logistics_note,
            'shipment_status' => $shipment?->shipment_status ?? 'pending_allocation',
            'can_edit' => false,
        ];
    }

    private function shipmentRow(Shipment $shipment): array
    {
        $order = $shipment->order;
        return [
            'id' => (int) $shipment->id,
            'order_id' => (int) $order->id,
            'order_number' => $order->erp_order_id ?: $order->external_order_number,
            'order_url' => '/orders/'.$order->id,
            'recipient_name' => $order->address?->recipient_name,
            'country' => $order->address?->country,
            'sku' => $order->items->map(fn ($item) => ($item->source_sku ?: $item->sku).' × '.(int) $item->quantity)->implode('、'),
            'warehouse' => $shipment->warehouse?->name,
            'carrier' => $shipment->carrier,
            'service' => $shipment->service,
            'tracking_number' => $shipment->tracking_number,
            'logistics_note' => $shipment->logistics_note,
            'shipment_status' => $shipment->shipment_status,
            'can_edit' => true,
        ];
    }
}
