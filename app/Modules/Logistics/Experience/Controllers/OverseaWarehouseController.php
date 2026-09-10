<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\OverseaWarehouseReviewService;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class OverseaWarehouseController
{
    private const PER_PAGE = 25;
    private const VIEWS = ['review', 'pending', 'shipped', 'exceptions', 'all'];
    private const PROGRESS = ['pending_review', 'sending_pending', 'shipping', 'shipped'];

    public function __construct(private readonly OverseaWarehouseReviewService $reviews) {}

    public function index(Request $request, ?string $warehouse = null): Response
    {
        $warehouseKey = $warehouse ?: 'all';
        abort_unless($warehouseKey === 'all' || array_key_exists($warehouseKey, OverseaWarehouseReviewService::WAREHOUSE_CODE_BY_KEY), 404);
        $view = in_array((string) $request->query('view'), self::VIEWS, true) ? (string) $request->query('view') : 'review';
        $warehouseMap = $this->warehouseMap();
        $selectedWarehouse = $warehouseKey === 'all' ? null : ($warehouseMap[$warehouseKey] ?? null);
        if ($warehouseKey !== 'all') abort_unless($selectedWarehouse, 404, '仓库尚未建立');

        $base = Shipment::query()
            ->with(['order.address', 'order.items.product', 'warehouse', 'overseaReview'])
            ->whereIn('warehouse_id', collect($warehouseMap)->pluck('id')->all())
            ->whereHas('order', fn ($q) => $q
                ->where('order_status', '!=', 'cancelled')
                ->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')));
        if ($selectedWarehouse) $base->where('warehouse_id', $selectedWarehouse['id']);
        $this->applyView($base, $view);

        $shipments = $base->latest('id')->paginate(self::PER_PAGE)->withQueryString();
        $start = ($shipments->currentPage() - 1) * $shipments->perPage();

        return Inertia::render('Logistics/Oversea/Show', [
            'warehouseKey' => $warehouseKey,
            'warehouse' => $selectedWarehouse,
            'warehouseOptions' => array_values($warehouseMap),
            'view' => $view,
            'rows' => $shipments->through(fn (Shipment $shipment, int $index): array => $this->row($shipment, $start + $index + 1))->toArray(),
            'viewCounts' => $this->viewCounts($warehouseMap, $selectedWarehouse),
            'canOperate' => $warehouseKey !== 'all' && (bool) $request->user()?->can('logistics.operate'),
        ]);
    }

    public function updateDraft(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['sometimes', 'required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'progress' => ['sometimes', 'required', 'string', Rule::in(self::PROGRESS)],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        $this->reviews->updateDraft($order, $data, $request->user());
        return back()->with('success', '海外仓审核信息已保存');
    }

    public function approve(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['note' => ['sometimes', 'nullable', 'string', 'max:1000']]);
        $this->reviews->approve($order, $request->user(), isset($data['note']) ? trim((string) $data['note']) : null);
        return back()->with('success', '订单已通过审核，进入待发货');
    }

    public function reopenReview(Request $request, Order $order): RedirectResponse
    {
        $reopened = $this->reviews->reopenReview($order, $request->user());
        $code = $reopened->shipment?->warehouse?->code;
        $key = array_search($code, OverseaWarehouseReviewService::WAREHOUSE_CODE_BY_KEY, true);
        abort_unless(is_string($key), 422, '该订单不是海外仓订单');
        return redirect("/logistics/oversea/{$key}?view=review")->with('success', '订单已重新进入审核，可重新分配仓库');
    }

    public function reject(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);
        $this->reviews->reject($order, trim($data['note']), $request->user());
        return back()->with('success', '订单已驳回并退回运营重新分仓');
    }

    private function warehouseMap(): array
    {
        $labels = [
            'us-ny' => '美东纽约',
            'us-moreplus' => '美西MorePlus',
            'us-sm' => '美西世迈',
            'de-tianyun' => '德国天运',
            'ca' => '加拿大仓',
        ];
        $byCode = Warehouse::query()
            ->where('is_active', true)
            ->whereIn('code', array_values(OverseaWarehouseReviewService::WAREHOUSE_CODE_BY_KEY))
            ->get()->keyBy('code');

        return collect(OverseaWarehouseReviewService::WAREHOUSE_CODE_BY_KEY)
            ->mapWithKeys(function (string $code, string $key) use ($byCode, $labels): array {
                $warehouse = $byCode->get($code);
                if (! $warehouse) return [];
                return [$key => [
                    'key' => $key,
                    'id' => $warehouse->id,
                    'code' => $code,
                    'label' => $labels[$key],
                    'carrier' => OverseaWarehouseReviewService::CARRIER_BY_CODE[$code] ?? null,
                    'url' => "/logistics/oversea/{$key}",
                ]];
            })->all();
    }

    private function applyView($query, string $view): void
    {
        if ($view === 'review') {
            $query->where('shipment_status', 'pending')->where(function ($q): void {
                $q->whereDoesntHave('overseaReview')
                    ->orWhereHas('overseaReview', fn ($r) => $r->where('status', '!=', 'approved'))
                    ->orWhereHas('overseaReview', fn ($r) => $r
                        ->where('status', 'approved')
                        ->whereColumn('reviewed_warehouse_id', '!=', 'shipments.warehouse_id'));
            });
        } elseif ($view === 'pending') {
            $query->where('shipment_status', 'pending')
                ->whereHas('overseaReview', fn ($r) => $r
                    ->where('status', 'approved')
                    ->whereColumn('reviewed_warehouse_id', 'shipments.warehouse_id'));
        } elseif ($view === 'shipped') {
            $query->where('shipment_status', 'shipped');
        } elseif ($view === 'exceptions') {
            $query->where('shipment_status', 'exception');
        }
    }

    private function viewCounts(array $warehouseMap, ?array $selectedWarehouse): array
    {
        $counts = [];
        foreach (self::VIEWS as $view) {
            $query = Shipment::query()->whereIn('warehouse_id', collect($warehouseMap)->pluck('id')->all())
                ->whereHas('order', fn ($q) => $q->where('order_status', '!=', 'cancelled')
                    ->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')));
            if ($selectedWarehouse) $query->where('warehouse_id', $selectedWarehouse['id']);
            $this->applyView($query, $view);
            $counts[$view] = $query->count();
        }
        return $counts;
    }

    private function row(Shipment $shipment, int $line): array
    {
        $order = $shipment->order;
        $review = $shipment->overseaReview;
        $effectiveApproved = $review?->status === 'approved'
            && (int) $review->reviewed_warehouse_id === (int) $shipment->warehouse_id;

        return [
            'line' => $line,
            'order_id' => $order?->id,
            'erp_order_id' => $order?->erp_order_id,
            'order_url' => $order ? ($order->order_kind?->value === 'kol_fulfillment' ? "/kol/orders/{$order->id}" : "/customers/orders/{$order->id}") : null,
            'recipient_name' => $order?->address?->recipient_name,
            'country' => $order?->address?->country,
            'warehouse_id' => $shipment->warehouse_id,
            'warehouse_name' => $shipment->warehouse?->name,
            'carrier' => OverseaWarehouseReviewService::CARRIER_BY_CODE[$shipment->warehouse?->code] ?? $shipment->carrier,
            'tracking_number' => $shipment->tracking_number,
            'progress' => $review?->progress ?? 'pending_review',
            'note' => $review?->note,
            'review_status' => $effectiveApproved ? 'approved' : 'pending',
            'shipment_status' => $shipment->shipment_status,
            'items' => $order?->items->map(fn ($item): array => [
                'sku' => $item->sku,
                'quantity' => (int) $item->quantity,
            ])->values()->all() ?? [],
        ];
    }
}
