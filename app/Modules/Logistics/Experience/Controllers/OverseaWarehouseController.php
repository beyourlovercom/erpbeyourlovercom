<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\OverseaTrackingReturnService;
use App\Modules\Logistics\Application\OverseaWarehouseReviewService;
use App\Modules\Logistics\Infrastructure\Models\Carrier;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class OverseaWarehouseController
{
    private const PER_PAGE = 25;
    private const VIEWS = ['review', 'pending', 'shipped', 'exceptions', 'all'];

    public function __construct(
        private readonly OverseaWarehouseReviewService $reviews,
        private readonly OverseaTrackingReturnService $trackingReturns,
    ) {}

    public function index(Request $request, ?string $warehouse = null): Response
    {
        $warehouse = in_array($warehouse, ['us-sm', 'us-sj', 'usa-sj'], true) ? 'us-3sm' : $warehouse;
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
            'carrierOptions' => Carrier::query()->where('is_active', true)->orderBy('name')->pluck('name')->values()->all(),
            'trackingImportPreview' => $warehouseKey === 'all' ? null : (collect((array) $request->session()->get($this->trackingSessionKey($warehouseKey), []))->except('normalized_rows')->all() ?: null),
        ]);
    }

    public function export(Request $request, string $warehouse): StreamedResponse
    {
        $warehouse = in_array($warehouse, ['us-sm', 'us-sj', 'usa-sj'], true) ? 'us-3sm' : $warehouse;
        $warehouseMap = $this->warehouseMap();
        $selectedWarehouse = $warehouseMap[$warehouse] ?? null;
        abort_unless($selectedWarehouse, 404, '仓库尚未建立');

        $shipments = Shipment::query()
            ->with(['order.address', 'order.items.product', 'warehouse', 'overseaReview'])
            ->where('warehouse_id', $selectedWarehouse['id'])
            ->whereHas('order', fn ($q) => $q
                ->where('order_status', '!=', 'cancelled')
                ->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')));
        $this->applyView($shipments, 'pending');
        $rows = $shipments->latest('id')->get();
        $filename = sprintf('007-%s-orders-%s.csv', $warehouse, now()->format('ymd'));

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['订单号', '收件人', '电话', '地址', '地址2', '城市', '州/省', '邮编', '国家', 'SKU/数量', '仓库', 'Carrier', 'Tracking No.', '发货时间(ISO8601含时区)', '备注']);
            foreach ($rows as $shipment) {
                $order = $shipment->order;
                $address = $order?->address;
                $items = $order?->items?->map(fn ($item) => ($item->sku ?: '无SKU').' x '.(int) $item->quantity)->implode(' / ') ?? '';
                fputcsv($out, [
                    $order?->erp_order_id, $address?->recipient_name, $address?->phone,
                    $address?->address_line_1, $address?->address_line_2, $address?->city,
                    $address?->province_state, $address?->postal_code, $address?->country,
                    $items, $shipment->warehouse?->name, $shipment->carrier,
                    $shipment->tracking_number, $shipment->shipped_at?->toIso8601String(), $shipment->overseaReview?->note,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function previewTrackingReturn(Request $request, string $warehouse): RedirectResponse
    {
        $warehouse = in_array($warehouse, ['us-sm', 'us-sj', 'usa-sj'], true) ? 'us-3sm' : $warehouse;
        abort_unless(array_key_exists($warehouse, OverseaWarehouseReviewService::WAREHOUSE_CODE_BY_KEY), 404);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:10240']]);
        $preview = $this->trackingReturns->preview($warehouse, $request->file('file'));
        $request->session()->put($this->trackingSessionKey($warehouse), $preview);

        return redirect("/logistics/oversea/{$warehouse}?view=pending")
            ->with('success', sprintf('单号表预览完成：可应用 %d，重复/已应用 %d，阻断 %d。', $preview['apply_count'], $preview['noop_count'], $preview['blocked_count']));
    }

    public function applyTrackingReturn(Request $request, string $warehouse): RedirectResponse
    {
        $warehouse = in_array($warehouse, ['us-sm', 'us-sj', 'usa-sj'], true) ? 'us-3sm' : $warehouse;
        abort_unless(array_key_exists($warehouse, OverseaWarehouseReviewService::WAREHOUSE_CODE_BY_KEY), 404);
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);
        $key = $this->trackingSessionKey($warehouse);
        $stored = $request->session()->get($key);
        abort_unless(is_array($stored), 409, '没有有效的单号表预览，请重新上传。');
        $result = $this->trackingReturns->apply($warehouse, $stored, $data['token'], $request->user());
        $request->session()->forget($key);
        $syncCompleted = collect($result['sync'])->where('status', 'completed')->count();
        $syncAttention = collect($result['sync'])->whereNotIn('status', ['completed'])->count();

        return redirect("/logistics/oversea/{$warehouse}?view=shipped")
            ->with('success', sprintf('回传已应用：发货 %d，重复跳过 %d，Woo 已验证 %d，需关注 %d。', $result['applied_count'], $result['noop_count'], $syncCompleted, $syncAttention));
    }

    public function updateDraft(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['sometimes', 'required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'carrier' => ['sometimes', 'required', 'string', 'max:191', Rule::exists('logistics_carriers', 'name')->where('is_active', true)],
            'tracking_number' => ['sometimes', 'nullable', 'string', 'max:128'],
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

    private function trackingSessionKey(string $warehouseKey): string
    {
        return 'logistics.oversea_tracking_return.'.$warehouseKey;
    }

    private function warehouseMap(): array
    {
        $byCode = Warehouse::query()
            ->where('is_active', true)
            ->whereIn('code', array_values(OverseaWarehouseReviewService::WAREHOUSE_CODE_BY_KEY))
            ->get()->keyBy('code');

        return collect(OverseaWarehouseReviewService::WAREHOUSE_CODE_BY_KEY)
            ->mapWithKeys(function (string $code, string $key) use ($byCode): array {
                $warehouse = $byCode->get($code);
                if (! $warehouse) return [];
                return [$key => [
                    'key' => $key,
                    'id' => $warehouse->id,
                    'code' => $code,
                    'label' => $warehouse->name,
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
            'order_url' => $order ? "/orders/{$order->id}" : null,
            'recipient_name' => $order?->address?->recipient_name,
            'country' => $order?->address?->country,
            'warehouse_id' => $shipment->warehouse_id,
            'warehouse_name' => $shipment->warehouse?->name,
            'carrier' => $shipment->carrier ?: (OverseaWarehouseReviewService::CARRIER_BY_CODE[$shipment->warehouse?->code] ?? null),
            'carrier_selection_source' => $shipment->carrier_selection_source,
            'tracking_number' => $shipment->tracking_number,
            'progress' => $review?->progress ?? 'pending_review',
            'note' => $review?->note,
            'review_status' => $effectiveApproved ? 'approved' : 'pending',
            'shipment_status' => $shipment->shipment_status,
            'items' => $order?->items->map(fn ($item): array => [
                'sku' => $item->resolved_fulfillment_sku ?: $item->sku,
                'source_sku' => $item->source_sku ?: $item->sku,
                'quantity' => (int) $item->quantity,
            ])->values()->all() ?? [],
        ];
    }
}
