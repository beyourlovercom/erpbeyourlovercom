<?php

namespace App\Modules\Orders\Experience\Controllers;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderService;
use App\Modules\Orders\Application\OrderSourceType;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Audit\Infrastructure\Models\AuditRecord;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class OrderController
{
    public function __construct(
        private readonly OrderService $orders,
    ) {}

    public function index(Request $request): Response
    {
        $kind = $this->kind($request);
        $orders = $this->orders->list([...$request->only(['search', 'source_type', 'order_status']), 'order_kind' => $kind->value]);

        return Inertia::render('Orders/Index', [
            'orderKind' => $kind->value,
            'orders' => $orders->through(fn (Order $order): array => $this->orderData($order))->toArray(),
            'filters' => $request->only(['search', 'source_type', 'order_status']),
            'sourceTypes' => array_map(fn (OrderSourceType $source): array => ['value' => $source->value, 'label' => $this->sourceLabel($source)], OrderSourceType::cases()),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Orders/Create', [
            'defaultOrderKind' => $this->kind($request)->value,
            'orderKinds' => array_map(fn (OrderKind $kind): array => ['value' => $kind->value, 'label' => $this->kindLabel($kind)], OrderKind::cases()),
            'sourceTypes' => array_map(fn (OrderSourceType $source): array => ['value' => $source->value, 'label' => $this->sourceLabel($source)], OrderSourceType::cases()),
            'owners' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate($this->rules());
        $items = $data['items'];
        unset($data['items']);
        $data['owner_id'] ??= $request->user()->getAuthIdentifier();
        $order = $this->orders->create($data, $items, $request->user());

        $routeName = $order->order_kind === OrderKind::KolFulfillment ? 'kol.orders.show' : 'customers.orders.show';

        return redirect()->route($routeName, $order)->with('success', '订单已创建');
    }

    public function show(Request $request, Order $order): Response
    {
        $this->authorizeKind($request, $order);
        $order->load(['items', 'owner', 'address', 'shipment', 'manualSync']);

        return Inertia::render('Orders/Show', [
            'order' => $this->orderData($order),
            'audit' => AuditRecord::query()
                ->where('entity_type', Order::class)
                ->where('entity_id', (string) $order->getKey())
                ->latest('created_at')->limit(30)->get()
                ->map(fn (AuditRecord $record): array => [
                    'action' => $record->action, 'actor_id' => $record->actor_id,
                    'created_at' => $record->created_at?->toIso8601String(), 'reason' => $record->reason,
                ])->all(),
        ]);
    }

    public function update(Request $request, Order $order): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeKind($request, $order);
        $data = $request->validate($this->updateRules($order));
        $items = $data['items'] ?? null;
        unset($data['items']);
        $this->orders->update($order, $data, $items, $request->user());

        return back()->with('success', '订单已更新');
    }

    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $kind = $this->kind($request);
        $orders = $this->orders->exportCursor([...$request->only(['search', 'source_type', 'order_status']), 'order_kind' => $kind->value]);

        return response()->streamDownload(function () use ($orders): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['订单号', '来源', '订单日期', '收件人', '国家', '状态', '负责人']);
            foreach ($orders as $order) {
                fputcsv($handle, [
                    $order->external_order_number, $this->sourceLabel($order->source_type),
                    $order->order_date?->toDateString(), $order->address?->recipient_name,
                    $order->address?->country, $order->order_status->value, $order->owner?->name,
                ]);
            }
            fclose($handle);
        }, 'orders.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function kind(Request $request): OrderKind
    {
        return OrderKind::from((string) $request->route('order_kind'));
    }

    private function authorizeKind(Request $request, Order $order): void
    {
        abort_if($order->order_kind !== $this->kind($request), HttpResponse::HTTP_NOT_FOUND);
    }

    private function orderData(Order $order): array
    {
        return [
            'id' => $order->id,
            'internal_order_number' => $order->internal_order_number,
            'external_order_number' => $order->external_order_number,
            'source_type' => $order->source_type->value,
            'source_label' => $this->sourceLabel($order->source_type),
            'order_kind' => $order->order_kind?->value,
            'order_kind_label' => $order->order_kind ? $this->kindLabel($order->order_kind) : null,
            'order_date' => $order->order_date?->toDateString(),
            'order_status' => $order->order_status->value,
            'order_status_label' => $this->statusLabel($order->order_status->value),
            'order_note' => $order->order_note,
            'owner' => $order->owner ? ['id' => $order->owner->id, 'name' => $order->owner->name] : null,
            'address' => $order->address?->only(['recipient_name', 'phone', 'country', 'province_state', 'city', 'address_line_1', 'address_line_2', 'postal_code']),
            'items' => $order->items->map(fn ($item): array => $item->only(['id', 'product_summary', 'sku', 'quantity', 'unit_price', 'line_revenue']))->values()->all(),
            'shipment' => $order->shipment?->only(['shipment_status', 'carrier', 'tracking_number', 'shipped_at', 'logistics_note', 'handled_by']),
            'manual_sync' => $order->manualSync ? [
                'status' => $order->manualSync->website_sync_status->value,
                'synced_at' => $order->manualSync->synced_at?->toIso8601String(),
                'synced_by' => $order->manualSync->synced_by,
                'sync_note' => $order->manualSync->sync_note,
            ] : null,
        ];
    }

    private function rules(): array
    {
        return [
            'order_kind' => ['required', Rule::in(array_column(OrderKind::cases(), 'value'))],
            'source_type' => ['required', 'in:website,kol,manual_other'],
            'external_order_number' => ['required', 'string', 'max:128', Rule::unique('orders', 'external_order_number')],
            'order_date' => ['required', 'date'],
            'order_note' => ['nullable', 'string', 'max:5000'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'recipient_name' => ['required', 'string', 'max:255'], 'phone' => ['required', 'string', 'max:64'],
            'country' => ['required', 'string', 'max:128'], 'province_state' => ['required', 'string', 'max:128'],
            'city' => ['required', 'string', 'max:128'], 'address_line_1' => ['required', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'], 'postal_code' => ['nullable', 'string', 'max:32'],
            'items' => ['required', 'array', 'min:1'], 'items.*.product_summary' => ['required', 'string', 'max:255'],
            'items.*.sku' => ['nullable', 'string', 'max:128'], 'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'decimal:0,2', 'min:0'],
        ];
    }

    private function updateRules(Order $order): array
    {
        return [
            'order_kind' => ['sometimes', Rule::in(array_column(OrderKind::cases(), 'value'))],
            'source_type' => ['sometimes', 'in:website,kol,manual_other'],
            'external_order_number' => ['sometimes', 'string', 'max:128', Rule::unique('orders', 'external_order_number')->ignore($order->id)],
            'order_date' => ['sometimes', 'date'],
            'order_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'owner_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'recipient_name' => ['sometimes', 'string', 'max:255'], 'phone' => ['sometimes', 'string', 'max:64'],
            'country' => ['sometimes', 'string', 'max:128'], 'province_state' => ['sometimes', 'string', 'max:128'],
            'city' => ['sometimes', 'string', 'max:128'], 'address_line_1' => ['sometimes', 'string', 'max:255'],
            'address_line_2' => ['sometimes', 'nullable', 'string', 'max:255'], 'postal_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'items' => ['sometimes', 'array', 'min:1'], 'items.*.product_summary' => ['required_with:items', 'string', 'max:255'],
            'items.*.sku' => ['nullable', 'string', 'max:128'], 'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            'items.*.unit_price' => ['required_with:items', 'decimal:0,2', 'min:0'],
        ];
    }

    private function sourceLabel(OrderSourceType $source): string
    {
        return match ($source) { OrderSourceType::Website => 'Website', OrderSourceType::Kol => 'KOL', OrderSourceType::ManualOther => 'Manual Other' };
    }

    private function kindLabel(OrderKind $kind): string
    {
        return match ($kind) { OrderKind::Customer => '客户订单', OrderKind::KolFulfillment => '网红履约订单' };
    }

    private function statusLabel(string $status): string
    {
        return ['pending_shipment' => '待发货', 'shipped' => '已发货', 'cancelled' => '已取消'][$status] ?? $status;
    }
}
