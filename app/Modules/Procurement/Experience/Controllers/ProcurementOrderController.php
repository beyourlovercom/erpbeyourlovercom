<?php

namespace App\Modules\Procurement\Experience\Controllers;

use App\Modules\Identity\Application\AuthorizationKeys;
use App\Modules\NotificationCenter\Application\NotificationPublisher;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Procurement\Application\ExternalProcurementWorkflow;
use App\Modules\Procurement\Application\ProcurementOrderService;
use App\Modules\Procurement\Application\ProcurementOrderStatus;
use App\Modules\Procurement\Infrastructure\Models\ProcurementOrder;
use App\Modules\Shell\Application\GlobalGridPilot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class ProcurementOrderController
{
    public function __construct(
        private readonly ProcurementOrderService $service,
        private readonly ExternalProcurementWorkflow $external,
        private readonly NotificationPublisher $notifications,
    ) {}

    public function index(Request $request): Response
    {
        $rawStatus = trim((string) $request->query('status', ''));
        $status = $rawStatus === '' ? null : ProcurementOrderStatus::tryFrom($rawStatus);
        abort_if($rawStatus !== '' && $status === null, 422, '无效的采购状态。');
        $search = trim((string) $request->query('search', ''));

        $query = ProcurementOrder::query()->with(['sourceOrder:id,erp_order_id,external_order_number', 'items']);
        if ($status) {
            $query->where('status', $status->value);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $like = '%'.$search.'%';
                $q->where('procurement_number', 'like', $like)
                    ->orWhereHas('sourceOrder', fn ($order) => $order->where('erp_order_id', 'like', $like)->orWhere('external_order_number', 'like', $like))
                    ->orWhereHas('items', fn ($items) => $items->where('sku', 'like', $like)->orWhere('product_summary', 'like', $like));
            });
        }

        $toRow = function (ProcurementOrder $order): array {
            $status = ProcurementOrderStatus::tryFrom($order->status);

            return [
                'id' => $order->id,
                'procurement_number' => $order->procurement_number,
                'status' => $order->status,
                'status_label' => $status?->label() ?? $order->status,
                'source_order_id' => $order->source_order_id,
                'source_order_number' => $order->sourceOrder?->erp_order_id,
                'source_order_erp_id' => $order->sourceOrder?->erp_order_id,
                'source_order_external_number' => $order->sourceOrder?->external_order_number,
                'item_summary' => $order->items->take(2)->map(fn ($item) => $item->product_summary ?: ($item->sku ?: '—'))->implode(' / '),
                'item_count' => $order->items->count(),
                'purchase_quantity' => (int) $order->items->sum('purchase_quantity'),
                'carrier' => $order->carrier,
                'tracking_number' => $order->tracking_number,
                'updated_at' => $order->updated_at?->toIso8601String(),
            ];
        };
        $query->orderByDesc('created_at')->orderByDesc('id');
        // Only the isolated Dev pilot uses bounded server pagination. The legacy
        // response and permissions remain unchanged for all other requests.
        $pilot = GlobalGridPilot::enabled($request);
        $gridPageSize = in_array((string) $request->query('per_page', '50'), ['20', '50', '100'], true)
            ? (int) $request->query('per_page', 50) : 50;
        $gridOrders = $pilot ? $query->paginate($gridPageSize)->through($toRow)->toArray() : null;
        $orders = $pilot ? [] : $query->get()->map($toRow)->values()->all();

        return Inertia::render('Procurement/Orders/Index', [
            'orders' => $orders,
            'gridOrders' => $gridOrders,
            'globalGridPilotAvailable' => GlobalGridPilot::available(),
            'globalGridPilotEnabled' => $pilot,
            'filters' => ['status' => $rawStatus, 'search' => $search],
            'statusOptions' => ProcurementOrderStatus::options(),
            'canOperate' => $request->user()->can(AuthorizationKeys::ProcurementOperate->value),
        ]);
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

        if ($procurement->wasRecentlyCreated) {
            $this->notifications->publishToPermission(AuthorizationKeys::ProcurementOperate->value, [
                'category' => 'procurement',
                'title' => '新的采购订单',
                'message' => $order->external_order_number.' 已创建采购订单 '.$procurement->procurement_number.'，请处理采购。',
                'action_url' => '/purchase/orders/'.$procurement->id,
                'entity_type' => 'procurement_order',
                'entity_id' => $procurement->id,
                'actor_name' => $request->user()->name,
                'related_links' => [
                    ['label' => '采购订单', 'value' => $procurement->procurement_number, 'url' => '/purchase/orders/'.$procurement->id],
                    ['label' => '源订单', 'value' => $order->external_order_number, 'url' => '/customers/orders/'.$order->id],
                ],
            ], (int) $request->user()->getAuthIdentifier());
        }

        return redirect()->route('procurement.orders.show', $procurement)->with('success', '采购订单已创建');
    }

    public function show(Request $request, ProcurementOrder $procurementOrder): Response
    {
        abort_unless($request->user()->can(AuthorizationKeys::ProcurementView->value), 403);
        $procurementOrder->load(['items', 'sourceOrder', 'creator:id,name', 'updater:id,name']);
        $status = ProcurementOrderStatus::tryFrom($procurementOrder->status);

        return Inertia::render('Procurement/Orders/Show', [
            'procurementOrder' => $procurementOrder->only([
                'id', 'procurement_number', 'status', 'carrier', 'tracking_number', 'completion_reason', 'completed_at',
                'source_order_id', 'created_at', 'updated_at', 'status_updated_at',
                'origin', 'received_at', 'received_by', 'receipt_note', 'released_at', 'released_by',
            ]) + [
                'status_label' => $status?->label() ?? $procurementOrder->status,
                'source_order_number' => $procurementOrder->sourceOrder?->external_order_number ?? $procurementOrder->sourceOrder?->erp_order_id,
                'created_by_name' => $procurementOrder->creator?->name,
                'updated_by_name' => $procurementOrder->updater?->name,
                'items' => $procurementOrder->items->map(fn ($item) => $item->only([
                    'id', 'sku', 'product_summary', 'source_quantity', 'purchase_url', 'purchase_size', 'purchase_quantity',
                    'received_quantity', 'received_sku',
                ]))->all(),
            ],
            'statusOptions' => $procurementOrder->origin === 'external'
                ? array_values(array_filter(ProcurementOrderStatus::options(), fn (array $row) => in_array($row['value'], ['pending', 'purchasing', 'ordered', 'arrived'], true)))
                : array_values(array_filter(ProcurementOrderStatus::options(), fn (array $row) => ! in_array($row['value'], ['arrived', 'released'], true))),
            'canOperate' => $request->user()->can(AuthorizationKeys::ProcurementOperate->value),
        ]);
    }

    public function update(Request $request, ProcurementOrder $procurementOrder): RedirectResponse
    {
        abort_unless($request->user()->can(AuthorizationKeys::ProcurementOperate->value), 403);
        if ($procurementOrder->origin === 'external') {
            $data = $request->validate([
                'status' => ['required', Rule::in(['pending', 'purchasing', 'ordered', 'arrived'])],
                'carrier' => ['nullable', 'string', 'max:128'],
                'tracking_number' => ['nullable', 'string', 'max:128'],
                'receipt_note' => ['required_if:status,arrived', 'nullable', 'string', 'max:500'],
                'items' => ['sometimes', 'array'],
                'items.*.id' => ['required_with:items', 'integer', 'min:1'],
                'items.*.purchase_url' => ['nullable', 'url', 'max:2048'],
                'items.*.purchase_size' => ['nullable', 'string', 'max:255'],
                'items.*.purchase_quantity' => ['nullable', 'integer', 'min:1'],
                'items.*.received_sku' => ['nullable', 'string', 'max:128'],
                'items.*.received_quantity' => ['nullable', 'integer', 'min:0'],
            ]);
            $this->external->updateProgress($procurementOrder, $data['status'], $data, $request->user());

            return back()->with('success', '外采进度已更新');
        }
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'purchasing', 'ordered', 'completed', 'cancelled'])],
            'carrier' => ['nullable', 'string', 'max:128'],
            'tracking_number' => ['nullable', 'string', 'max:128'],
        ]);
        $statusChanged = $procurementOrder->status !== $data['status'];
        $nextCompleted = $data['status'] === ProcurementOrderStatus::Completed->value;
        $procurementOrder->fill([
            'status' => $data['status'],
            'carrier' => $this->nullableTrim($data['carrier'] ?? null),
            'tracking_number' => $this->nullableTrim($data['tracking_number'] ?? null),
            'completion_reason' => $nextCompleted ? ($procurementOrder->status === ProcurementOrderStatus::Completed->value ? $procurementOrder->completion_reason : 'manual') : null,
            'completed_at' => $nextCompleted ? ($procurementOrder->completed_at ?? now()) : null,
            'updated_by' => $request->user()->getAuthIdentifier(),
            'status_updated_at' => $statusChanged ? now() : $procurementOrder->status_updated_at,
        ])->save();

        return back()->with('success', '采购订单已更新');
    }

    public function releaseToDomestic(Request $request, ProcurementOrder $procurementOrder): RedirectResponse
    {
        abort_unless($request->user()->can('orders.edit') && $request->user()->can('domestic.review'),
            403, '仅 Vita 可确认外采到货并放行');
        $this->external->release($procurementOrder, $request->user());

        return redirect('/logistics/china?view=pending')
            ->with('success', '外采到货已确认，已跳过 Kaven 二次审核并进入国内待发货');
    }

    public function legacyShow(ProcurementOrder $procurementOrder): RedirectResponse
    {
        return redirect()->route('procurement.orders.show', $procurementOrder);
    }

    private function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
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
