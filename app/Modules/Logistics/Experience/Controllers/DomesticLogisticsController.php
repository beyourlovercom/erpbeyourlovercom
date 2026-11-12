<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Shell\Application\GlobalGridPilot;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\CarrierServiceCatalog;
use App\Modules\Logistics\Application\Domestic\DomesticPendingBatchExporter;
use App\Modules\Logistics\Application\Domestic\DomesticCarrierDefaults;
use App\Modules\Logistics\Application\Domestic\DomesticReviewAccess;
use App\Modules\Logistics\Application\Domestic\DomesticReviewDecisionService;
use App\Modules\Logistics\Application\Domestic\DomesticTodayOrdersExporter;
use App\Modules\Logistics\Application\Domestic\DomesticTrackingReturnService;
use App\Modules\Logistics\Application\Domestic\DomesticTrackingReturnReader;
use App\Modules\Logistics\Application\Domestic\SpeedaOutboundExporter;
use App\Modules\Logistics\Application\LogisticsExecutionAccess;
use App\Modules\Logistics\Application\LogisticsTrackingListStatus;
use App\Modules\Logistics\Application\LogisticsTrackingPushAccess;
use App\Modules\Logistics\Application\OverseaFiles\WarehouseOrderReference;
use App\Modules\Orders\Application\OrderProgressQuery;
use App\Modules\Orders\Application\OrderWorkflowStatusProjection;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

final class DomesticLogisticsController
{
    private const REVIEW_MARKER = 'domestic_reviewed';

    private const VIEWS = ['review', 'stockout', 'pending', 'today-4px', 'today-weship', 'exceptions', 'today', '4px', 'weship'];

    public function __construct(
        private readonly SpeedaOutboundExporter $speeda,
        private readonly DomesticPendingBatchExporter $pendingBatch,
        private readonly DomesticTodayOrdersExporter $todayOrders,
        private readonly DomesticTrackingReturnService $tracking,
        private readonly WarehouseOrderReference $references,
        private readonly DomesticReviewDecisionService $decisions,
        private readonly DomesticReviewAccess $reviewAccess,
        private readonly OrderProgressQuery $progressQueries,
        private readonly DomesticCarrierDefaults $carrierDefaults,
    ) {}

    public function index(Request $request): Response
    {
        $factory = $this->factory();
        $view = in_array((string) $request->query('view', 'review'), self::VIEWS, true)
            ? (string) $request->query('view', 'review')
            : 'review';

        $query = $this->baseQuery((int) $factory->id)->with([
            'address',
            'items.product',
            'shipment.warehouse',
            'operationalMarkers',
            'manualSync',
            'financials',
            'kolCollaboration',
            'fulfillmentParent.financials',
            'fulfillmentParent.shipment.warehouse',
            'fulfillmentParent.kolCollaboration',
            'fulfillmentParent',
            'fulfillmentAllocation',
            'shipment.overseaReview',
            'originalFulfillmentChildren.shipment.warehouse',
            'originalFulfillmentChildren.shipment.overseaReview',
            'originalFulfillmentChildren.operationalMarkers',
        ]);

        $this->applyView($query, $view);
        // R4-7A: the cumulative 4PX view has an independent Global Grid gate.
        // The Weship historical view remains native until R4-7A Owner PASS.
        // Preserve original provider-only predicate, all shipment states,
        // existing shipping guards and native fallback.
        $approvedGridView = in_array($view, ['review', 'stockout', 'pending', 'today', 'today-4px', 'today-weship', 'exceptions', '4px'], true);
        $gridSetting = $request->query('global_grid');
        $gridAvailable = $approvedGridView || GlobalGridPilot::available();
        $gridPilot = $approvedGridView
            ? ($gridSetting === null || $gridSetting === '1')
            : GlobalGridPilot::enabled($request);
        $search = $gridPilot ? trim(mb_substr((string) $request->query('search', ''), 0, 100)) : '';
        if ($search !== '') {
            $term = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function (Builder $query) use ($term): void {
                $query->where('erp_order_id', 'like', $term)
                    ->orWhere('external_order_number', 'like', $term)
                    ->orWhereHas('address', fn (Builder $address) => $address->where('recipient_name', 'like', $term));
            });
        }
        $requestedSize = (int) $request->query('per_page', 50);
        $pageSize = $gridPilot && in_array($requestedSize, [20, 50, 100], true) ? $requestedSize : 50;
        $rows = $query->orderBy('order_date')->orderBy('id')->paginate($pageSize)->withQueryString();
        $trackingStatuses = app(LogisticsTrackingListStatus::class)->forShipments($rows->getCollection()
            ->map(fn (Order $order): int => (int) $order->shipment?->id)->all());
        $rows->through(fn (Order $order, int $index): array => $this->row($order, (($rows->currentPage() - 1) * $rows->perPage()) + $index + 1, $trackingStatuses[(int) $order->shipment?->id] ?? null, $view === 'exceptions'));

        // Inertia partial row reloads do not need nine independent view counts.
        // Keep every count identical on full loads and when viewCounts is requested.
        $loadViewCounts = function () use ($factory): array {
            $counts = [];
            foreach (self::VIEWS as $key) {
                $countQuery = $this->baseQuery((int) $factory->id);
                $this->applyView($countQuery, $key);
                $counts[$key] = $countQuery->count();
            }

            return $counts;
        };

        $previewProvider = session('domestic_tracking_preview_provider');
        $preview = $previewProvider ? session("domestic_tracking_preview_{$previewProvider}") : null;

        return Inertia::render('Logistics/Domestic/Index', [
            'view' => $view,
            'rows' => $rows,
            'globalGridPilotAvailable' => $gridAvailable,
            'globalGridPilotEnabled' => $gridPilot,
            'gridSearch' => $search,
            'viewCounts' => $loadViewCounts,
            'canOperate' => app(LogisticsExecutionAccess::class)->allows($request->user()),
            'canReview' => $this->reviewAccess->allows($request->user()),
            'canArchive' => (bool) $request->user()?->can('orders.archive'),
            'canTrackingSync' => app(LogisticsTrackingPushAccess::class)->allows($request->user()),
            'carrierOptions' => fn () => app(CarrierServiceCatalog::class)->options(),
            'trackingImportProvider' => $previewProvider,
            'trackingImportPreview' => $preview ? [
                'token' => $preview['token'],
                'rows' => $preview['rows'],
                'apply_count' => $preview['apply_count'],
                'noop_count' => $preview['noop_count'],
                'blocked_count' => $preview['blocked_count'],
            ] : null,
            'providerStatus' => [
                '4px' => ['export_ready' => true, 'return_ready' => true, 'label' => '速达 / 4PX'],
                'weship' => ['export_ready' => true, 'return_ready' => true, 'label' => '纬狮 / Weship', 'note' => '纬狮独立保留原 24 列模板 · XLSX 下载 · XLS/XLSX 回传'],
            ],
        ]);
    }

    /** Run the existing authorized per-order review service, without bulk bypass. */
    public function batchReview(Request $request): \Illuminate\Http\JsonResponse
    {
        abort_unless($this->reviewAccess->allows($request->user()), 403);
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:50'],
            'order_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'action' => ['required', 'in:approve,stockout,reject,restock'],
            'reason' => ['required_if:action,reject', 'nullable', 'string', 'min:3', 'max:500'],
        ]);
        $results = [];
        foreach ($data['order_ids'] as $id) {
            $id = (int) $id;
            try {
                $order = Order::query()->findOrFail($id);
                $this->decisions->decide($order, $data['action'], $data['reason'] ?? null, $request->user());
                $results[] = ['order_id' => $id, 'ok' => true, 'message' => '已处理'];
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
                $results[] = ['order_id' => $id, 'ok' => false,
                    'message' => $exception->getStatusCode() === 403 ? '无操作权限' : ($exception->getMessage() ?: '状态已变化，无法执行')];
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $results[] = ['order_id' => $id, 'ok' => false, 'message' => '订单不存在'];
            }
        }
        return response()->json([
            'results' => $results,
            'success_count' => count(array_filter($results, fn (array $row): bool => $row['ok'])),
            'failed_count' => count(array_filter($results, fn (array $row): bool => ! $row['ok'])),
        ]);
    }

    public function approve(Request $request, Order $order): RedirectResponse
    {
        abort_unless($this->reviewAccess->allows($request->user()), 403);
        $this->decisions->decide($order, 'approve', null, $request->user());

        return back()->with('success', '有货确认通过，订单已进入待发货。');
    }

    public function restock(Request $request, Order $order): RedirectResponse
    {
        abort_unless($this->reviewAccess->allows($request->user()), 403);
        $this->decisions->decide($order, 'restock', null, $request->user());

        return redirect('/logistics/china?view=pending')
            ->with('success', '已确认到货，订单已进入待发货。');
    }

    public function stockout(Request $request, Order $order): RedirectResponse
    {
        abort_unless($this->reviewAccess->allows($request->user()), 403);
        $this->decisions->decide($order, 'stockout', null, $request->user());

        return back()->with('success', '已标记缺货，发货流程已阻断。');
    }

    public function reject(Request $request, Order $order): RedirectResponse
    {
        abort_unless($this->reviewAccess->allows($request->user()), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $this->decisions->decide($order, 'reject', $data['reason'], $request->user());

        return redirect('/logistics/china?view=review')->with('success', '已驳回并退回 Vita 待分仓，原因已写入订单详情。');
    }

    public function reopenReview(Request $request, Order $order): RedirectResponse
    {
        abort_unless($this->reviewAccess->allows($request->user()), 403);
        $this->assertDomesticPending($order);
        $order->operationalMarkers()->where('marker', self::REVIEW_MARKER)->delete();

        return back()->with('success', '订单已退回待审核。');
    }

    public function export(string $provider): HttpResponse
    {
        $provider = $this->provider($provider);
        $carrier = $provider === '4px' ? '4px' : 'weship';
        $label = $this->providerLabel($provider);

        $factory = $this->factory();
        $orders = $this->progressQueries->domesticReady($this->baseQuery((int) $factory->id))
            ->with(['address', 'shipment', 'items.product', 'externalReferences', 'operationalMarkers'])
            ->whereHas('shipment', fn ($q) => $q->whereRaw('LOWER(carrier) = ?', [$carrier])
                ->whereIn('carrier_selection_source', ['manual', 'automatic']))
            ->orderBy('order_date')
            ->orderBy('id')
            ->get()->filter(fn (Order $order): bool => $order->shipment?->carrier_selection_source === 'manual'
                || $this->carrierDefaults->ready($order));

        try {
            $result = $this->speeda->exportReady($orders, $label);
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }

        return response($result['bytes'], 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="007-'.$provider.'-orders-'.now()->format('ymd').'.xlsx"',
            'Cache-Control' => 'no-store, private',
            'X-BYL-Exported-Orders' => (string) $result['exported_count'],
            'X-BYL-Blocked-Orders' => (string) count($result['blocked']),
        ]);
    }

    /**
     * Read-only XLSX report: exactly the same unpaginated order population as
     * the "今日订单" tab. Each order line item is a separate spreadsheet row.
     */
    public function exportTodayOrders(): HttpResponse
    {
        $factory = $this->factory();
        $query = $this->baseQuery((int) $factory->id)
            ->with(['items', 'address', 'shipment', 'fulfillmentParent', 'externalReferences']);
        $this->applyView($query, 'today');
        $orders = $query->orderBy('order_date')->orderBy('id')->get();

        $bytes = $this->todayOrders->export($orders);

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="007-domestic-today-'.now()->format('Ymd').'.xlsx"',
            'Cache-Control' => 'no-store, private',
            'X-BYL-Exported-Orders' => (string) $orders->count(),
            'X-BYL-Exported-Lines' => (string) $orders->sum(fn (Order $order): int => max(1, $order->items->count())),
        ]);
    }

    public function exportPendingBatch(): HttpResponse
    {
        $factory = $this->factory();
        $orders = $this->progressQueries->domesticReady($this->baseQuery((int) $factory->id))
            ->with(['address', 'items.product', 'shipment', 'externalReferences', 'operationalMarkers'])

            ->orderBy('order_date')
            ->orderBy('id')
            ->get();

        try {
            $bytes = $this->pendingBatch->export($orders);
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="007-domestic-pending-'.now()->format('ymd').'.xlsx"',
            'Cache-Control' => 'no-store, private',
            'X-BYL-Exported-Orders' => (string) $orders->count(),
        ]);
    }

    public function previewUnifiedTrackingReturn(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'max:10240']]);
        try {
            $provider = app(DomesticTrackingReturnReader::class)->detectProvider($data['file']);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return $this->previewTrackingReturn($request, $provider);
    }

    public function previewPendingBatch(Request $request): RedirectResponse|JsonResponse
    {
        return $this->previewTrackingReturn($request, 'generic');
    }

    public function applyPendingBatch(Request $request): RedirectResponse|JsonResponse
    {
        return $this->applyTrackingReturn($request, 'generic');
    }

    public function previewTrackingReturn(Request $request, string $provider): RedirectResponse|JsonResponse
    {
        $provider = $this->provider($provider);
        $data = $request->validate(['file' => ['required', 'file', 'max:10240']]);
        $preview = $this->tracking->preview($provider, $data['file']);

        session()->put("domestic_tracking_preview_{$provider}", $preview);
        session()->put('domestic_tracking_preview_provider', $provider);

        $message = "已生成{$this->providerLabel($provider)}回传预览；确认前未写业务数据。";
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'provider' => $provider,
                'apply_count' => $preview['apply_count'], 'noop_count' => $preview['noop_count'],
                'blocked_count' => $preview['blocked_count']]);
        }

        return redirect('/logistics/china?view=pending')->with('success', $message);
    }

    public function applyTrackingReturn(Request $request, string $provider): RedirectResponse|JsonResponse
    {
        $provider = $this->provider($provider);
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);
        $key = "domestic_tracking_preview_{$provider}";
        $stored = session($key);
        abort_unless(is_array($stored), 409, '没有可应用的国内物流回传预览。');

        $result = $this->tracking->apply($provider, $stored, $data['token'], $request->user());
        session()->forget([$key, 'domestic_tracking_preview_provider']);

        $message = sprintf(
            '%s回传完成：成功 %d，重复 %d，阻断 %d，执行失败 %d。阻断/失败订单不会影响其他正常订单。',
            $this->providerLabel($provider),
            $result['applied_count'],
            $result['noop_count'],
            $result['blocked_count'],
            $result['failed_count'],
        );

        if ($request->expectsJson()) {
            return response()->json(['message' => $message,
                'applied_count' => $result['applied_count'],
                'noop_count' => $result['noop_count'],
                'blocked_count' => $result['blocked_count'],
                'failed_count' => $result['failed_count'],
                'sync_pending_count' => collect($result['rows'])->where('sync', 'pending')->count(),
                'sync_handoff_failed_count' => collect($result['rows'])->where('sync', 'handoff_failed')->count(),
            ]);
        }

        return redirect('/logistics/china?view=pending')->with('success', $message);
    }

    private function baseQuery(int $factoryId): Builder
    {
        return Order::query()->eligibleForOperations()
            ->where('order_status', '!=', 'cancelled')
            ->whereDoesntHave('fulfillmentChildren')
            ->whereDoesntHave('externalReferences', fn ($q) => $q->where('provider', 'historical_logistics'))
            ->whereHas('shipment', fn ($q) => $q->where('warehouse_id', $factoryId));
    }

    private function applyView(Builder $query, string $view): void
    {
        match ($view) {
            'review' => $this->progressQueries->domesticReview($query),
            'stockout' => $this->progressQueries->domesticStockout($query),
            'pending' => $this->progressQueries->domesticReady($query),
            'today-4px' => $this->progressQueries->domesticTodayProvider($query, '4px'),
            'today-weship' => $this->progressQueries->domesticTodayProvider($query, 'weship'),
            'exceptions' => $query->where(function (Builder $issues): void {
                $issues->whereHas('shipment', fn ($q) => $q->where('shipment_status', 'exception'))
                    ->orWhere(fn (Builder $conflicts) => $this->progressQueries->applyPhase($conflicts, 'progress_conflict'));
            }),
            'today' => $this->progressQueries->domesticToday($query),
            '4px' => $query->whereHas('shipment', fn ($q) => $q->whereRaw('LOWER(carrier) = ?', ['4px'])),
            'weship' => $query->whereHas('shipment', fn ($q) => $q->whereRaw('LOWER(carrier) = ?', ['weship'])),
            default => null,
        };
    }

    private function row(Order $order, int $line, ?array $tracking = null, bool $includeException = false): array
    {
        $shipment = $order->shipment;
        $trackingState = app(LogisticsTrackingListStatus::class)->currentStatus($tracking, $order->manualSync?->website_sync_status);
        $stockout = $order->workflow_status === 'out_of_stock' || $order->operationalMarkers->contains('marker', 'domestic_stockout');
        $approved = ! $stockout && $order->operationalMarkers->contains('marker', self::REVIEW_MARKER);
        $projection = $this->workflowProjection($order);
        $conflict = $projection['code'] === 'progress_conflict';
        $reference = null;
        try {
            $reference = $this->references->warehouse($order);
        } catch (\DomainException) {
        }

        $businessDate = app(\App\Modules\Orders\Application\OrderBusinessDateResolver::class)->domesticReview($order);
        $amount = app(\App\Modules\Orders\Application\DomesticReviewAmountResolver::class)->resolve($order);

        $exception = $includeException
            ? $this->exceptionSummary($order, $conflict, $stockout, $approved)
            : [];

        return [
            ...$exception,
            'line' => $line,
            'paid_date' => $businessDate['date'],
            'paid_date_source' => $businessDate['source'],
            'amount' => $amount['amount'],
            'amount_currency' => $amount['currency'],
            'amount_source' => $amount['source'],
            'order_id' => (int) $order->id,
            'source_type' => ($order->fulfillment_parent_id ? $order->fulfillmentParent?->source_type : $order->source_type) instanceof \BackedEnum
                ? ($order->fulfillment_parent_id ? $order->fulfillmentParent?->source_type : $order->source_type)->value
                : (string) ($order->fulfillment_parent_id ? $order->fulfillmentParent?->source_type : $order->source_type),
            'tracking_sync_status' => $trackingState['status'],
            'tracking_sync_reason' => $trackingState['reason'],
            'tracking_sync_verified_at' => $trackingState['verified_at'] ?? null,
            'erp_order_id' => $order->erp_order_id,
            'workflow_status_projection' => $projection,
            'provider_reference' => $reference,
            'order_url' => '/customers/orders/'.$order->id,
            'recipient_name' => $order->address?->recipient_name,
            'phone' => $order->address?->phone,
            'country' => $order->address?->country,
            'state' => $order->address?->province_state,
            'city' => $order->address?->city,
            'postal_code' => $order->address?->postal_code,
            'carrier' => $shipment?->carrier,
            'service' => $shipment?->service,
            'carrier_selection_source' => $shipment?->carrier_selection_source,
            'carrier_ready' => $this->carrierDefaults->ready($order),
            'tracking_number' => $shipment?->tracking_number,
            'shipment_status' => $shipment?->shipment_status,
            'shipped_at' => $shipment?->shipped_at?->toIso8601String(),
            'review_status' => $conflict ? 'conflict' : ($stockout ? 'stockout' : ($approved ? 'approved' : 'pending')),
            'export_blockers' => $shipment?->shipment_status !== 'pending'
                ? ['该单已离开待发货队列，禁止重复导出']
                : ($stockout ? ['缺货，禁止导出'] : (! $approved ? ['商务尚未审核，禁止导出']
                    : (! $this->carrierDefaults->ready($order)
                        ? ['请检查物流商和服务类型']
                        : (in_array(strtolower((string) $shipment?->carrier), ['4px', 'weship'], true)
                        ? $this->speeda->blockers($order, strtolower((string) $shipment?->carrier) === '4px' ? '速达' : '纬狮')
                        : ['未选定速达或纬狮承运商'])))),
            'items' => $order->items->map(fn ($item) => [
                'sku' => $item->sku,
                'quantity' => (int) $item->quantity,
            ])->values()->all(),
        ];
    }

    /**
     * Read-only reason based exclusively on existing shipment/order facts.
     * Do not create new exception codes or imply a resolution was performed.
     * @return array{exception_type: string, exception_reason: string}
     */
    private function exceptionSummary(Order $order, bool $conflict, bool $stockout, bool $approved): array
    {
        $shipment = $order->shipment;
        if ($shipment?->shipment_status === 'exception') {
            $note = trim((string) ($shipment->logistics_note ?? ''));

            return [
                'exception_type' => '发货异常',
                'exception_reason' => $note !== '' ? mb_substr($note, 0, 200) : '发货被标记异常，请查看原物流记录',
            ];
        }

        $reason = match (true) {
            $stockout && in_array($shipment?->shipment_status, ['shipped', 'completed'], true)
                => '订单标记缺货，但物流已发货',
            $shipment?->shipment_status === 'shipped' && $shipment->shipped_at === null
                => '物流已发货，但缺少发货时间',
            $shipment?->shipment_status === 'shipped' && ! $approved && blank($shipment->tracking_number)
                => '物流已发货，但缺少商务审核和追踪单号',
            $order->order_status?->value === 'shipped' && $shipment?->shipment_status === 'pending'
                => '订单已标记发货，但物流仍为待发货',
            default => '履约状态不一致，请打开订单详情核对',
        };

        return [
            'exception_type' => $conflict ? '进度冲突' : '发货异常',
            'exception_reason' => $reason,
        ];
    }

    private function workflowProjection(Order $order): array
    {
        return app(OrderWorkflowStatusProjection::class)->for($order);
    }

    private function assertDomesticPending(Order $order): void
    {
        $factory = $this->factory();
        $order->loadMissing(['shipment', 'externalReferences']);
        abort_if($order->externalReferences->contains('provider', 'historical_logistics'), 422, '历史物流证据订单不能进入当前国内发货。');
        abort_unless($order->shipment && (int) $order->shipment->warehouse_id === (int) $factory->id, 422, '订单不属于中国工厂。');
        abort_unless($order->shipment->shipment_status === 'pending', 422, '只有待发货订单可以审核。');
    }

    private function factory(): Warehouse
    {
        return Warehouse::query()->whereRaw('UPPER(code) = ?', ['FACTORY'])->where('is_active', true)->firstOrFail();
    }

    private function provider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        abort_unless(in_array($provider, ['4px', 'weship', 'generic'], true), 404);

        return $provider;
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            '4px' => '速达', 'weship' => '纬狮', default => '待发货批量'
        };
    }
}
