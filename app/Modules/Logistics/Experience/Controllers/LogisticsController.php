<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use App\Modules\Logistics\Application\AutomaticAllocationEligibilityService;
use App\Modules\Logistics\Application\AssistedFulfillmentService;
use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Logistics\Application\WarehouseRecommendationService;
use App\Modules\Logistics\Infrastructure\Models\Carrier;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Models\LogisticsReview;
use App\Modules\Finance\Application\OrderAnalysisService;
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
        private readonly AutomaticAllocationEligibilityService $automaticAllocationEligibility,
        private readonly AssistedFulfillmentService $assistedFulfillment,
        private readonly InventoryAvailabilityReader $availability,
        private readonly WarehouseRecommendationService $recommendations,
        private readonly OrderPackagingReferenceProjection $packagingReferences,
        private readonly OrderAnalysisService $analysis,
    ) {}

    public function index(Request $request): Response
    {
        $orders = Order::query()->with(['address', 'items.product', 'owner', 'shipment.warehouse'])
            ->where('order_status', 'pending_shipment')->whereDoesntHave('externalReferences', fn ($q) => $q->where('provider', 'historical_logistics'))->whereDoesntHave('logisticsReview', fn ($q) => $q->where('status', 'pending'))->latest('order_date')->latest('id')
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
            'statusBar' => $this->statusBar(),
        ]);
    }

    public function saveDraft(Request $request, Order $order): \Illuminate\Http\RedirectResponse
    {
        $this->assistedFulfillment->saveDraft($order, $request->validate($this->draftRules()), $request->user());

        return back()->with('success', '履约草稿已保存');
    }

    public function adoptRecommendation(Request $request, Order $order): \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $result = $this->assistedFulfillment->adoptRecommendation($order, $request->user());

        if ($request->expectsJson()) {
            $shipment = $result->shipment;

            return response()->json([
                'warehouse_id' => $shipment?->warehouse_id,
                'warehouse_name' => $shipment?->warehouse?->name,
            ]);
        }

        return back()->with('success', '已采用系统推荐并预留库存');
    }

    public function awaitingTracking(Request $request): Response
    {
        $shipments = Shipment::query()
            ->with(['order.address', 'order.items.product', 'order.logisticsReview', 'order.operationalMarkers', 'warehouse'])
            ->where('shipment_status', 'shipped')
            ->whereNotNull('shipped_at')
            ->whereNull('tracking_number')
            ->whereDoesntHave('order.logisticsReview', fn ($q) => $q->where('status', 'pending'))
            ->whereHas('order', fn ($q) => $q->where('order_status', '!=', 'cancelled')->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))
            ->latest('shipped_at')->latest('id')
            ->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('Logistics/AwaitingTracking', [
            'shipments' => $shipments->through(fn ($s) => $this->queueRow($s))->toArray(),
            'statusBar' => $this->statusBar(),
        ]);
    }

    public function inTransit(Request $request): Response
    {
        $shipments = Shipment::query()
            ->with(['order.address', 'order.items.product', 'order.logisticsReview', 'warehouse'])
            ->where('shipment_status', 'shipped')
            ->whereNotNull('tracking_number')
            ->where('tracking_number', '!=', '')
            ->whereDoesntHave('order.logisticsReview', fn ($q) => $q->where('status', 'pending'))
            ->whereHas('order', fn ($q) => $q->where('order_status', '!=', 'cancelled')->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))
            ->latest('shipped_at')->latest('id')
            ->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('Logistics/InTransit', [
            'shipments' => $shipments->through(fn ($s) => $this->queueRow($s))->toArray(),
            'statusBar' => $this->statusBar(),
        ]);
    }

    public function exceptions(Request $request): Response
    {
        $shipments = Shipment::query()
            ->with(['order.address', 'order.items.product', 'order.logisticsReview', 'warehouse'])
            ->where('shipment_status', 'exception')
            ->whereDoesntHave('order.logisticsReview', fn ($q) => $q->where('status', 'pending'))
            ->whereHas('order', fn ($q) => $q->where('order_status', '!=', 'cancelled')->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))
            ->latest('shipped_at')->latest('id')
            ->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('Logistics/Exceptions', [
            'shipments' => $shipments->through(fn ($s) => $this->queueRow($s))->toArray(),
            'statusBar' => $this->statusBar(),
        ]);
    }

    public function review(Request $request): Response
    {
        $reviews = LogisticsReview::query()->with(['order.address', 'order.items.product', 'order.shipment.warehouse'])
            ->where('status', 'pending')->whereHas('order', fn ($q) => $q->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))
            ->latest('requested_at')->paginate(self::PER_PAGE)->withQueryString();
        return Inertia::render('Logistics/Review', ['reviews' => $reviews->through(fn (LogisticsReview $review): array => [...$this->queueRow($review->order->shipment), 'review_id' => $review->id, 'reason' => $review->reason, 'trigger' => $review->trigger, 'requested_at' => $review->requested_at?->toIso8601String()])->toArray(), 'statusBar' => $this->statusBar()]);
    }

    public function completed(Request $request): Response { return $this->projection($request, 'completed', 'Logistics/Completed', fn ($q) => $q->where('shipment_status', 'completed')); }
    public function cancelled(Request $request): Response
    {
        $orders = Order::query()->with(['address', 'items.product', 'shipment.warehouse'])->where('order_status', 'cancelled')->whereDoesntHave('externalReferences', fn ($q) => $q->where('provider', 'historical_logistics'))->latest('order_date')->latest('id')->paginate(self::PER_PAGE)->withQueryString();
        return Inertia::render('Logistics/Cancelled', ['orders' => $orders->through(fn (Order $order) => $this->orderProjection($order))->toArray(), 'statusBar' => $this->statusBar()]);
    }
    public function loss(Request $request): Response
    {
        $orders = Order::query()->with(['address', 'items.product', 'shipment.warehouse'])->whereDoesntHave('externalReferences', fn ($q) => $q->where('provider', 'historical_logistics'))->latest('order_date')->latest('id')->get()->filter(fn (Order $order) => $this->analysis->summary($order)['contribution_profit'] !== null && (float) $this->analysis->summary($order)['contribution_profit'] < 0)->values();
        return Inertia::render('Logistics/Loss', ['orders' => $orders->map(fn (Order $order) => $this->orderProjection($order))->values()->all(), 'statusBar' => $this->statusBar()]);
    }

    public function decideReview(Request $request, Order $order): \Illuminate\Http\RedirectResponse { $data = $request->validate(['destination' => ['required', 'string'], 'decision_note' => ['required', 'string', 'max:5000']]); $this->shipments->decideReview($order, $data['destination'], $data['decision_note'], $request->user()); return back()->with('success', '复核已处理'); }
    public function pause(Request $request, Order $order): \Illuminate\Http\RedirectResponse { $data = $request->validate(['logistics_note' => ['required', 'string', 'max:5000']]); $this->shipments->pauseException($order, $data['logistics_note'], $request->user()); return back()->with('success', '订单已暂停'); }
    public function redispatch(Request $request, Order $order): \Illuminate\Http\RedirectResponse { $data = $request->validate(['logistics_note' => ['required', 'string', 'max:5000']]); $this->shipments->requestReview($order, 'redispatch', $data['logistics_note'], $request->user()); return back()->with('success', '重新派件已提交复核'); }
    public function reship(Request $request, Order $order): \Illuminate\Http\RedirectResponse { $data = $request->validate(['logistics_note' => ['required', 'string', 'max:5000']]); $this->shipments->reship($order, $data, $request->user()); return back()->with('success', '已保留原始履约证据并重置待发货'); }
    public function contactUser(Request $request, Order $order): \Illuminate\Http\RedirectResponse { $data = $request->validate(['logistics_note' => ['required', 'string', 'max:5000']]); $this->shipments->contactUser($order, $data['logistics_note'], $request->user()); return back()->with('success', '已记录联系用户'); }
    public function cancelException(Request $request, Order $order): \Illuminate\Http\RedirectResponse { $data = $request->validate(['logistics_note' => ['required', 'string', 'max:5000']]); $this->shipments->cancelException($order, $data['logistics_note'], $request->user()); return back()->with('success', '订单已取消，未执行实际退款'); }
    public function markCompleted(Request $request, Order $order): \Illuminate\Http\RedirectResponse { $this->shipments->markCompleted($order, $request->user()); return back()->with('success', '物流已标记完结'); }

    public function updateTracking(Request $request, Order $order): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate(['tracking_number' => ['required', 'string', 'max:128']]);
        $this->shipments->updateTracking($order, $data['tracking_number'], $request->user());

        return back()->with('success', '追踪号已更新');
    }

    public function markException(Request $request, Order $order): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate(['logistics_note' => ['required', 'string', 'max:5000']]);
        $this->shipments->markException($order, $data['logistics_note'], $request->user());

        return back()->with('success', '已标记物流异常');
    }

    public function resolveException(Request $request, Order $order): \Illuminate\Http\RedirectResponse
    {
        $this->shipments->resolveException($order, $request->user());

        return back()->with('success', '异常已解除，物流已恢复运输中');
    }

    public function markShipped(Request $request, Order $order): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'carrier' => ['required', 'string', 'max:128', Rule::exists('logistics_carriers', 'name')->where('is_active', true)],
            'service' => ['required', 'string', 'max:128'],
            'shipped_at' => ['required', 'date'],
            'tracking_number' => ['nullable', 'string', 'max:128'],
            'logistics_note' => ['nullable', 'string', 'max:5000'],
        ]);
        $this->assistedFulfillment->markShipped($order, $data, $request->user());
        $msg = empty($data['tracking_number']) ? '已标记发货，追踪号待填入' : '已标记发货，已生成待同步任务';

        return back()->with('success', $msg);
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

    private function queueRow(Shipment $shipment): array
    {
        $order = $shipment->order;
        $order?->loadMissing('operationalMarkers');

        return [
            'shipment_id' => $shipment->id,
            'order_id' => $order?->id,
            'external_order_number' => $order?->external_order_number,
            'erp_order_id' => $order?->erp_order_id,
            'recipient_name' => $order?->address?->recipient_name,
            'country' => $order?->address?->country,
            'warehouse_name' => $shipment->warehouse?->name,
            'carrier' => $shipment->carrier,
            'service' => $shipment->service,
            'tracking_number' => $shipment->tracking_number,
            'shipped_at' => $shipment->shipped_at?->toIso8601String(),
            'logistics_note' => $shipment->logistics_note,
            'shipment_status' => $shipment->shipment_status,
            'order_url' => $this->orderUrl($order),
            'items' => $order?->items->map(fn ($item): array => ['sku' => $item->sku, 'product_summary' => $item->product_summary, 'product_id' => $item->product_id, 'product_url' => $item->product ? "/products/{$item->product_id}" : null, 'quantity' => $item->quantity])->values()->all() ?? [],
            'review_pending' => $order?->logisticsReview?->status === 'pending',
            'paused' => $order?->operationalMarkers?->contains('marker', 'logistics_paused') ?? false,
        ];
    }

    private function projection(Request $request, string $kind, string $component, \Closure $filter): Response { $shipments = Shipment::query()->with(['order.address', 'order.items.product', 'order.logisticsReview', 'warehouse'])->where($filter)->whereDoesntHave('order.logisticsReview', fn ($q) => $q->where('status', 'pending'))->whereHas('order', fn ($q) => $q->where('order_status', '!=', 'cancelled')->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))
            ->latest('shipped_at')->latest('id')->paginate(self::PER_PAGE)->withQueryString(); return Inertia::render($component, ['shipments' => $shipments->through(fn ($s) => $this->queueRow($s))->toArray(), 'statusBar' => $this->statusBar()]); }
    private function orderProjection(Order $order): array { return ['id' => $order->id, 'order_id' => $order->id, 'order_number' => $order->external_order_number ?? $order->erp_order_id, 'order_url' => $this->orderUrl($order), 'items' => $order->items->map(fn ($item) => ['sku' => $item->sku, 'product_summary' => $item->product_summary, 'product_id' => $item->product_id, 'product_url' => $item->product ? "/products/{$item->product_id}" : null])->all()]; }
    private function orderUrl(?Order $order): ?string { if (! $order) return null; return $order->order_kind?->value === 'kol_fulfillment' ? "/kol/orders/{$order->id}" : "/customers/orders/{$order->id}"; }
    private function statusBar(): array { $review = LogisticsReview::query()->where('status', 'pending')->whereHas('order', fn ($q) => $q->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))->count(); $active = fn ($q) => $q->whereDoesntHave('order.logisticsReview', fn ($r) => $r->where('status', 'pending'))->whereHas('order', fn ($o) => $o->where('order_status', '!=', 'cancelled')->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics'))); $loss = Order::query()->with(['financials', 'costEntries'])->whereDoesntHave('externalReferences', fn ($q) => $q->where('provider', 'historical_logistics'))->get()->filter(fn ($o) => ($s = $this->analysis->summary($o))['contribution_profit'] !== null && (float) $s['contribution_profit'] < 0)->count(); return [['key' => 'pending', 'label' => '待发货', 'url' => '/logistics/pending', 'count' => Order::query()->where('order_status', 'pending_shipment')->whereDoesntHave('externalReferences', fn ($q) => $q->where('provider', 'historical_logistics'))->whereDoesntHave('logisticsReview', fn ($q) => $q->where('status', 'pending'))->count()], ['key' => 'awaiting', 'label' => '已发货', 'url' => '/logistics/awaiting-tracking', 'count' => Shipment::query()->where('shipment_status', 'shipped')->whereNotNull('shipped_at')->where(fn ($q) => $q->whereNull('tracking_number')->orWhere('tracking_number', ''))->where($active)->count()], ['key' => 'transit', 'label' => '运输中', 'url' => '/logistics/in-transit', 'count' => Shipment::query()->where('shipment_status', 'shipped')->whereNotNull('tracking_number')->where('tracking_number', '!=', '')->where($active)->count()], ['key' => 'exception', 'label' => '异常单', 'url' => '/logistics/exceptions', 'count' => Shipment::query()->where('shipment_status', 'exception')->where($active)->count()], ['key' => 'review', 'label' => '复核单', 'url' => '/logistics/review', 'count' => $review], ['key' => 'completed', 'label' => '已完结', 'url' => '/logistics/completed', 'count' => Shipment::query()->where('shipment_status', 'completed')->where($active)->count()], ['key' => 'cancelled', 'label' => '已取消', 'url' => '/logistics/cancelled', 'count' => Order::query()->where('order_status', 'cancelled')->whereDoesntHave('externalReferences', fn ($q) => $q->where('provider', 'historical_logistics'))->count()], ['key' => 'loss', 'label' => '亏本单', 'url' => '/logistics/loss', 'count' => $loss]]; }

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
        $recommendation = $this->recommendations->recommend(
            $order->address?->country,
            $order->items->map(fn ($item): array => [
                'product_id' => $item->product_id,
                'quantity' => (int) $item->quantity,
            ])->values()->all(),
        );

        return [
            'id' => $order->id,
            'external_order_number' => $order->external_order_number,
            'order_url' => $this->orderUrl($order),
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
                'product_url' => $item->product ? "/products/{$item->product_id}" : null,
                'sku' => $item->sku,
                'product_summary' => $item->product_summary,
                'quantity' => $item->quantity,
                'availability' => $availability[$item->product_id] ?? [],
            ])->values()->all(),
            'warehouse_recommendation' => $recommendation,
            'automatic_allocation' => $this->automaticAllocationEligibility->evaluate($order, $recommendation),
            'shipment' => $order->shipment ? [
                ...$order->shipment->only(['warehouse_id', 'warehouse_selection_source', 'carrier', 'service', 'carrier_selection_source', 'tracking_number', 'shipped_at', 'logistics_note']),
                'warehouse_name' => $order->shipment->warehouse?->name,
            ] : null,
        ];
    }
}
