<?php

namespace App\Modules\Orders\Experience\Controllers;

use App\Modules\Audit\Infrastructure\Models\AuditRecord;
use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use App\Modules\Kol\Application\SavedViewService;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderPackagingReferenceProjection;
use App\Modules\Orders\Application\OrderService;
use App\Modules\Orders\Application\OrderSourceType;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class OrderController
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly InventoryAvailabilityReader $availability,
        private readonly SavedViewService $views,
        private readonly OrderPackagingReferenceProjection $packagingReferences,
    ) {}

    public function index(Request $request): Response
    {
        $kind = $this->kindOrNull($request);
        $filters = $request->only(['search', 'source_type', 'order_status', 'view']);
        $scope = (string) $request->route('order_scope');
        if ($scope === 'external_warehouse') {
            $filters['warehouse_ownership'] = 'external';
        }
        if ($kind) {
            $filters['order_kind'] = $kind->value;
        }
        $orders = $this->orders->list($filters);

        $isCustomerKind = $kind?->value === 'customer';
        $canExport = $isCustomerKind
            ? ($request->user()->can('customers.export') && $request->user()->can('system.permissions.manage'))
            : $request->user()->can('orders.view');

        return Inertia::render('Orders/Index', [
            'orderKind' => $kind?->value ?? 'all',
            'orderScope' => $scope === 'external_warehouse' ? 'external_warehouse' : 'all',
            'orders' => $orders->through(fn (Order $order): array => $this->orderData($order))->toArray(),
            'filters' => $request->only(['search', 'source_type', 'order_status', 'view']),
            'savedViews' => $this->views->forUser('orders', (int) $request->user()->getAuthIdentifier(), false),
            'sourceTypes' => array_map(fn (OrderSourceType $source): array => ['value' => $source->value, 'label' => $this->sourceLabel($source)], OrderSourceType::cases()),
            'canExport' => $canExport,
        ]);
    }

    public function create(Request $request): Response
    {
        $kind = $this->kind($request);

        return Inertia::render('Orders/Create', [
            'defaultOrderKind' => $kind->value,
            'orderKindLabel' => $this->kindLabel($kind),
            'generatedExternalOrderNumber' => $this->orders->generateExternalOrderNumber($kind),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules());
        $items = $data['items'];
        unset($data['items']);
        // Route context is authority: order_kind/source_type/owner cannot be overridden by client input.
        $data['order_kind'] = $this->kind($request)->value;
        $data['source_type'] = OrderSourceType::Manual->value;
        $data['currency'] ??= 'USD';
        $data['owner_id'] = $request->user()->getAuthIdentifier();
        $order = $this->orders->create($data, $items, $request->user());

        $routeName = $order->order_kind === OrderKind::KolFulfillment ? 'kol.orders.show' : 'customers.orders.show';

        return redirect()->route($routeName, $order)->with('success', '订单已创建');
    }

    public function showAny(Order $order): RedirectResponse
    {
        $target = $order;
        if ($order->order_kind === OrderKind::FulfillmentDerived) {
            $target = $order->fulfillmentParent()->firstOrFail();
        }

        return match ($target->order_kind) {
            OrderKind::Customer => redirect()->route('customers.orders.show', $target),
            OrderKind::KolFulfillment => redirect()->route('kol.orders.show', $target),
            default => abort(404),
        };
    }

    public function show(Request $request, Order $order): Response
    {
        $this->authorizeKind($request, $order);
        $order->load(['items', 'owner', 'address', 'shipment.warehouse', 'manualSync', 'operationalMarkers', 'procurementOrder']);
        $inventoryAvailability = $this->availability->forProductIds($order->items->pluck('product_id')->filter()->unique()->values()->all());

        return Inertia::render('Orders/Show', [
            'order' => $this->orderData($order),
            'inventoryAvailability' => $inventoryAvailability,
            'packagingReferences' => $this->packagingReferences->forOrder($order),
            'warehouses' => $this->availability->activeWarehouses(),
            'canMarkOperational' => $request->user()?->can('orders.edit') ?? false,
            'canCreateProcurement' => ($request->user()?->can('procurement.create') ?? false) && $order->operationalMarkers->contains('marker', 'stockout'),
            'canManageProductPackaging' => $request->user()?->can('products.operate') ?? false,
            'canViewPackagingEvidence' => ($request->user()?->can('inventory.view') ?? false) || ($request->user()?->can('inventory.packaging.review') ?? false),
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

    public function update(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeKind($request, $order);
        $data = $request->validate($this->updateRules($order));
        $items = $data['items'] ?? null;
        unset($data['items']);
        $this->orders->update($order, $data, $items, $request->user());

        return back()->with('success', '订单已更新');
    }

    public function setOperationalMarker(Request $request, Order $order, string $marker): RedirectResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $this->orders->setOperationalMarker($order, $marker, (bool) $data['active'], $request->user());

        return back()->with('success', '订单业务标记已更新');
    }

    public function export(Request $request): StreamedResponse
    {
        $kind = $this->kindOrNull($request);
        $filters = $request->only(['search', 'source_type', 'order_status', 'view']);
        $scope = (string) $request->route('order_scope');
        if ($scope === 'external_warehouse') {
            $filters['warehouse_ownership'] = 'external';
        }
        if ($kind) {
            $filters['order_kind'] = $kind->value;
        }
        $orders = $this->orders->exportCursor($filters);

        return response()->streamDownload(function () use ($orders): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ERP订单号', '来源订单号', '来源', '订单日期', '收件人', '国家', '状态', '负责人']);
            foreach ($orders as $order) {
                fputcsv($handle, [
                    $order->erp_order_id, $order->external_order_number, $this->sourceLabel($order->source_type),
                    $order->order_date?->toDateString(), $order->address?->recipient_name,
                    $order->address?->country, $order->order_status->value, $order->owner?->name,
                ]);
            }
            fclose($handle);
        }, 'orders.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function kind(Request $request): OrderKind
    {
        return $this->kindOrNull($request) ?? abort(404);
    }

    private function kindOrNull(Request $request): ?OrderKind
    {
        $value = (string) $request->route('order_kind');

        return $value === '' || $value === 'all' ? null : OrderKind::from($value);
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
            'erp_order_id' => $order->erp_order_id,
            'external_order_number' => $order->external_order_number,
            'source_type' => $order->source_type->value,
            'source_label' => $this->sourceLabel($order->source_type),
            'order_kind' => $order->order_kind?->value,
            'order_kind_label' => $order->order_kind ? $this->kindLabel($order->order_kind) : null,
            'order_date' => $order->order_date?->toDateString(),
            'order_status' => $order->order_status->value,
            'order_status_label' => $this->statusLabel($order->order_status->value),
            'order_note' => $order->order_note,
            'currency' => $order->currency,
            'owner' => $order->owner ? ['id' => $order->owner->id, 'name' => $order->owner->name] : null,
            'owner_label' => $order->owner ? 'Owner('.$order->owner->name.')' : null,
            'address' => $order->address?->only(['recipient_name', 'phone', 'country', 'province_state', 'city', 'address_line_1', 'address_line_2', 'postal_code']),
            'items' => $order->items->map(fn ($item): array => $item->only(['id', 'product_id', 'product_summary', 'sku', 'quantity', 'unit_price', 'line_revenue']))->values()->all(),
            'shipment' => $order->shipment ? [
                ...$order->shipment->only(['shipment_status', 'warehouse_id', 'warehouse_selection_source', 'carrier', 'service', 'carrier_selection_source', 'tracking_number', 'shipped_at', 'logistics_note', 'handled_by']),
                'warehouse_name' => $order->shipment->warehouse?->name,
            ] : null,
            'operational_markers' => $order->relationLoaded('operationalMarkers') ? $order->operationalMarkers->pluck('marker')->values()->all() : [],
            'procurement_order' => $order->relationLoaded('procurementOrder') && $order->procurementOrder ? ['id' => $order->procurementOrder->id, 'procurement_number' => $order->procurementOrder->procurement_number] : null,
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
            // order_kind/source_type/owner_id are forced from route context in store(); not client-settable.
            'external_order_number' => ['required', 'string', 'max:128', Rule::unique('orders', 'external_order_number')],
            'order_date' => ['required', 'date'],
            'order_note' => ['nullable', 'string', 'max:5000'],
            'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
            'recipient_name' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:64'],
            'country' => ['nullable', 'string', 'max:128'], 'province_state' => ['nullable', 'string', 'max:128'],
            'city' => ['nullable', 'string', 'max:128'], 'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'], 'postal_code' => ['nullable', 'string', 'max:32'],
            'items' => ['required', 'array', 'min:1'], 'items.*.product_summary' => ['nullable', 'string', 'max:255'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNotNull('sku')->where('sku', '!=', '')],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'decimal:0,2', 'min:0'],
        ];
    }

    private function updateRules(Order $order): array
    {
        return [
            // external_order_number and owner_id are immutable via the generic update path once saved (audit-preserving).
            'order_kind' => ['sometimes', Rule::in(array_column(OrderKind::cases(), 'value'))],
            'source_type' => ['sometimes', Rule::in(array_column(OrderSourceType::cases(), 'value'))],
            'order_date' => ['sometimes', 'date'],
            'order_note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'currency' => ['sometimes', 'required', 'string', 'regex:/^[A-Z]{3}$/'],
            'recipient_name' => ['sometimes', 'nullable', 'string', 'max:255'], 'phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'country' => ['sometimes', 'nullable', 'string', 'max:128'], 'province_state' => ['sometimes', 'nullable', 'string', 'max:128'],
            'city' => ['sometimes', 'nullable', 'string', 'max:128'], 'address_line_1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line_2' => ['sometimes', 'nullable', 'string', 'max:255'], 'postal_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'items' => ['sometimes', 'array', 'min:1'], 'items.*.product_summary' => ['sometimes', 'nullable', 'string', 'max:255'],
            'items.*.product_id' => ['required_with:items', 'integer', Rule::exists('products', 'id')->whereNotNull('sku')->where('sku', '!=', '')],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            'items.*.unit_price' => ['required_with:items', 'decimal:0,2', 'min:0'],
        ];
    }

    private function sourceLabel(OrderSourceType $source): string
    {
        return match ($source) {
            OrderSourceType::Website => 'Website',
            OrderSourceType::Kol => 'KOL',
            OrderSourceType::ManualOther => 'Manual Other',
            OrderSourceType::Manual => '手动创建',
            OrderSourceType::WooCommerce => 'WooCommerce',
        };
    }

    private function kindLabel(OrderKind $kind): string
    {
        return match ($kind) {
            OrderKind::Customer => '客户订单', OrderKind::KolFulfillment => '网红履约订单', OrderKind::FulfillmentDerived => '履约衍生订单'
        };
    }

    private function statusLabel(string $status): string
    {
        return ['pending_shipment' => '待发货', 'shipped' => '已发货', 'completed' => '已完结', 'completed_delivered' => '完结&送达', 'cancelled' => '已取消'][$status] ?? $status;
    }
}
