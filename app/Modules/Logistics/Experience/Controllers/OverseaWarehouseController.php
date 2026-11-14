<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\CarrierServiceCatalog;
use App\Modules\Logistics\Application\LogisticsExecutionAccess;
use App\Modules\Logistics\Application\LogisticsReviewAccess;
use App\Modules\Logistics\Application\LogisticsTrackingListStatus;
use App\Modules\Logistics\Application\LogisticsTrackingPushAccess;
use App\Modules\Logistics\Application\OverseaFiles\OverseaOutboundFileService;
use App\Modules\Logistics\Application\OverseaTrackingReturnService;
use App\Modules\Logistics\Application\OverseaWarehouseReviewService;
use App\Modules\Logistics\Application\ShipOut\ShipOutMorePlusVerifiedTrackingCompletion;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionDispatchState;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionV1OperatorAccess;
use App\Modules\Logistics\Application\WarehouseDispatchStatus;
use App\Modules\Logistics\Application\WarehouseSkuPresentation;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsWebDraftAttempt;
use App\Modules\Orders\Application\OrderProgressQuery;
use App\Modules\Orders\Application\OrderWorkflowStatus;
use App\Modules\Orders\Application\OrderWorkflowStatusProjection;
use App\Modules\Orders\Infrastructure\Models\Order;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class OverseaWarehouseController
{
    private const DEFAULT_PER_PAGE = 50;

    private const PER_PAGE_OPTIONS = [20, 50, 100];

    private const VIEWS = ['review', 'stockout', 'pending', 'issued', 'shipped', 'exceptions', 'all'];

    public function __construct(
        private readonly OverseaWarehouseReviewService $reviews,
        private readonly OverseaTrackingReturnService $trackingReturns,
        private readonly LogisticsExecutionAccess $executionAccess,
        private readonly ShipOutProductionV1OperatorAccess $shipOutV1Access,
        private readonly LogisticsReviewAccess $reviewAccess,
        private readonly CarrierServiceCatalog $carrierServices,
        private readonly OverseaOutboundFileService $outboundFiles,
        private readonly OrderWorkflowStatusProjection $workflowStatusProjection,
        private readonly OrderProgressQuery $progressQueries,
    ) {}

    public function index(Request $request, ?string $warehouse = null): Response
    {
        $warehouse = in_array($warehouse, ['us-sm', 'us-sj', 'usa-sj'], true) ? 'us-3sm' : $warehouse;
        $warehouseKey = $warehouse ?: 'all';
        abort_unless($warehouseKey === 'all' || array_key_exists($warehouseKey, OverseaWarehouseReviewService::WAREHOUSE_CODE_BY_KEY), 404);
        $requested = (string) $request->query('view');
        // Existing bookmarks remain usable; there are six visible queues.
        $view = match ($requested) {
            'pending_dispatch' => 'pending',
            'dispatched' => 'issued',
            default => in_array($requested, self::VIEWS, true) ? $requested : 'review',
        };
        $perPage = (int) $request->query('per_page', self::DEFAULT_PER_PAGE);
        if (! in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = self::DEFAULT_PER_PAGE;
        }
        $warehouseMap = $this->warehouseMap();
        $selectedWarehouse = $warehouseKey === 'all' ? null : ($warehouseMap[$warehouseKey] ?? null);
        if ($warehouseKey !== 'all') {
            abort_unless($selectedWarehouse, 404, '仓库尚未建立');
        }

        $base = Shipment::query()
            ->with(['order.address', 'order.items.product', 'order.manualSync', 'order.fulfillmentParent', 'order.fulfillmentAllocation', 'order.operationalMarkers', 'order.originalFulfillmentChildren.shipment.warehouse', 'order.originalFulfillmentChildren.shipment.overseaReview', 'order.originalFulfillmentChildren.operationalMarkers', 'warehouse', 'overseaReview.reviewer'])
            ->whereIn('warehouse_id', collect($warehouseMap)->pluck('id')->all())
            ->whereHas('order', fn ($q) => $q->eligibleForOperations()
                ->where('order_status', '!=', 'cancelled')
                ->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')));
        if ($selectedWarehouse) {
            $base->where('warehouse_id', $selectedWarehouse['id']);
        }
        $this->applyView($base, $view);

        // Global Grid V1: keep queries on canonical Shipment/Order records.
        // The legacy UI is a reversible presentation choice, not a new data path.
        $gridEnabled = (string) $request->query('global_grid', '1') !== '0';
        $gridSearch = mb_substr(trim((string) $request->query('search', '')), 0, 100);
        $gridFilters = [
            'carrier' => mb_substr(trim((string) $request->query('filter_carrier', '')), 0, 80),
            'tracking' => mb_substr(trim((string) $request->query('filter_tracking', '')), 0, 100),
        ];
        $sortable = ['line' => 'id', 'shipped_at' => 'shipped_at', 'tracking' => 'tracking_number'];
        $sortCol = (string) $request->query('sort_col', 'line');
        $sortCol = array_key_exists($sortCol, $sortable) ? $sortCol : 'line';
        $sortDir = strtolower((string) $request->query('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        if ($gridEnabled) {
            if ($gridSearch !== '') {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $gridSearch).'%';
                $base->where(fn ($q) => $q
                    ->where('tracking_number', 'like', $like)
                    ->orWhereHas('order', fn ($orders) => $orders->where('erp_order_id', 'like', $like))
                    ->orWhereHas('order.address', fn ($addresses) => $addresses->where('recipient_name', 'like', $like))
                    ->orWhereHas('order.items', fn ($items) => $items->where('sku', 'like', $like)));
            }
            foreach (['carrier' => 'carrier', 'tracking' => 'tracking_number'] as $key => $field) {
                if ($gridFilters[$key] !== '') {
                    $base->where($field, 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $gridFilters[$key]).'%');
                }
            }
        }

        $shipments = $base->orderBy($sortable[$gridEnabled ? $sortCol : 'line'], $gridEnabled ? $sortDir : 'desc')
            ->paginate($perPage)->withQueryString();
        $trackingStatuses = app(LogisticsTrackingListStatus::class)
            ->forShipments($shipments->getCollection()->pluck('id')->all());
        $start = ($shipments->currentPage() - 1) * $shipments->perPage();
        // One indexed batch read of OMS audit snapshots; no per-row network
        // queries. Nothing from the provider is fetched just to render a list.
        $xlwmsAttempts = $warehouseKey === 'us-3sm'
            ? XlwmsDispatchAttempt::query()
                ->whereIn('order_id', $shipments->getCollection()->pluck('order_id')->all())
                ->get()->keyBy('order_id')
            : collect();
        // Production V1 projection: local signed attempt ledger only; no
        // ShipOut HTTP request on list render or tab counts.
        $shipOutAttempts = in_array($warehouseKey, ['us-moreplus', 'all'], true)
            ? DB::table('shipout_dispatch_attempts')
                ->whereIn('order_id', $shipments->getCollection()->pluck('order_id')->all())
                ->where('provider_warehouse_id', ShipOutProductionDispatchState::MOREPLUS_PROVIDER_WAREHOUSE_ID)
                ->get(['order_id', 'status', 'provider_order_id', 'provider_warehouse_id'])
                ->keyBy('order_id')
            : collect();
        // Read-only, paginated batch audit projection; never calls ShipOut on list render.
        $shipOutBatchItems = in_array($warehouseKey, ['us-moreplus', 'all'], true)
            && Schema::hasTable('shipout_production_batch_items')
            ? DB::table('shipout_production_batch_items')
                ->whereIn('order_id', $shipments->getCollection()->pluck('order_id')->all())
                ->orderByDesc('id')->get(['order_id', 'status', 'result_code'])->unique('order_id')->keyBy('order_id')
            : collect();
        // Latest durable, sanitized signed OMS evidence: no provider HTTP
        // while listing, pagination or counting.
        $shipOutReadbacks = in_array($warehouseKey, ['us-moreplus', 'all'], true)
            && Schema::hasTable('shipout_oms_readbacks')
            ? DB::table('shipout_oms_readbacks')
                ->whereIn('order_id', $shipments->getCollection()->pluck('order_id')->all())
                ->orderByDesc('id')->get(['order_id', 'provider_order_id', 'provider_status', 'state', 'reason_code', 'checked_at'])
                ->unique('order_id')->keyBy('order_id')
            : collect();
        $webDraftAttempts = $warehouseKey === 'us-3sm'
            ? XlwmsWebDraftAttempt::query()->whereIn('order_id', $shipments->getCollection()->pluck('order_id')->all())
                ->latest('id')->get()->unique('order_id')->keyBy('order_id')
            : collect();
        $canRunXlwms = $warehouseKey === 'us-3sm'
            && $this->executionAccess->allows($request->user())
            && (bool) config('xlwms.live_dispatch_enabled', false);

        return Inertia::render('Logistics/Oversea/Show', [
            'warehouseKey' => $warehouseKey,
            'warehouse' => $selectedWarehouse,
            'warehouseOptions' => array_values($warehouseMap),
            'view' => $view,
            'globalGridEnabled' => $gridEnabled,
            'gridSearch' => $gridEnabled ? $gridSearch : '',
            'gridSort' => $gridEnabled ? ['colId' => $sortCol, 'direction' => $sortDir] : null,
            'gridFilters' => $gridEnabled ? $gridFilters : ['carrier' => '', 'tracking' => ''],
            'rows' => $shipments->through(fn (Shipment $shipment, int $index): array => [
                ...$this->row($shipment, $start + $index + 1, $xlwmsAttempts->get($shipment->order_id), $trackingStatuses[(int) $shipment->id] ?? null, $webDraftAttempts->get($shipment->order_id), $shipOutAttempts->get($shipment->order_id), $shipOutBatchItems->get($shipment->order_id), $shipOutReadbacks->get($shipment->order_id)),
                // Logistics access alone is not enough for editing an order's staff workflow progress.
                'can_edit_workflow_status' => $request->user()->can('orders.view')
                    && $request->user()->can('orders.edit')
                    && $shipment->order !== null
                    && $shipment->order->archived_at === null && ! $shipment->order->isOperationallyArchived(),
            ])->toArray(),
            'workflowStatusOptions' => collect(OrderWorkflowStatus::registry())->map(fn (array $definition, string $code): array => ['code' => $code, ...$definition])->values()->all(),
            'xlwmsAuthorizedOrderId' => $canRunXlwms ? (int) config('xlwms.live_order_id', 0) : 0,
            'xlwmsOmsWebEnabled' => $warehouseKey === 'us-3sm'
                && (bool) config('xlwms.oms_web_enabled', false)
                && filled(config('xlwms.oms_web_token'))
                && filled(config('xlwms.oms_web_hmac_key')),
            // Production V1 is visible as a separate, default-OFF workbench;
            // never make Sandbox flags active in Production.
            // Administrative visibility and read-only history remain available
            // while the real POST batch gate is OFF after provider duplicates.
            // Never couple viewing a disabled button to permission to POST.
            'shipoutProductionBatchUi' => $warehouseKey === 'us-moreplus'
                && (app()->environment(['local', 'testing'])
                    ? $request->user()?->can('logistics.view')
                    : (app()->environment('production')
                        && $this->shipOutV1Access->allows($request->user())
                        && (bool) config('shipout.production_read_enabled', false))),
            'shipoutProductionBatchPaused' => app()->environment('production')
                && (! (bool) config('shipout.production_v1_batch_enabled', false)
                    || ! (bool) config('shipout.production_v1_batch_owner_approved', false)),
            'shipoutProductionBatchDryRun' => ! app()->environment('production'),
            'shipoutProductionLatestBatch' => $warehouseKey === 'us-moreplus'
                && (app()->environment(['local', 'testing'])
                    || (app()->environment('production') && $this->shipOutV1Access->allows($request->user())))
                && Schema::hasTable('shipout_production_batches')
                ? DB::table('shipout_production_batches')
                    ->where('operator_user_id', (int) $request->user()->id)
                    ->orderByDesc('created_at')->orderByDesc('id')
                    ->first(['id', 'status', 'dry_run', 'total'])
                : null,
            'shipoutProductionUi' => $warehouseKey === 'us-moreplus'
                && app()->environment('production'),
            'shipoutProductionReadEnabled' => $warehouseKey === 'us-moreplus'
                && app()->environment('production')
                && (bool) config('shipout.production_read_enabled', false)
                && (bool) config('shipout.production_v1_read_enabled', false),
            'shipoutProductionMinOrderId' => $warehouseKey === 'us-moreplus'
                ? (int) config('shipout.production_v1_min_order_id', 0) : 0,
            'shipoutProductionOperatorAllowed' => $warehouseKey === 'us-moreplus'
                && app()->environment('production')
                && $this->shipOutV1Access->allows($request->user()),
            'shipoutProductionDispatchEnabled' => $warehouseKey === 'us-moreplus'
                && app()->environment('production')
                && (bool) config('shipout.production_read_enabled', false)
                && (bool) config('shipout.production_dispatch_enabled', false)
                && (bool) config('shipout.production_v1_read_enabled', false)
                && (bool) config('shipout.production_v1_dispatch_enabled', false)
                && (bool) config('shipout.production_v1_owner_approved', false)
                && $this->shipOutV1Access->allows($request->user())
                && (int) config('shipout.production_v1_min_order_id', 0) > 0,
            'shipoutSandboxReadEnabled' => $warehouseKey === 'us-moreplus'
                && ! app()->environment('production')
                && (bool) config('shipout.sandbox_read_enabled', false),
            'shipoutSandboxDispatchEnabled' => $warehouseKey === 'us-moreplus'
                && ! app()->environment('production')
                && (bool) config('shipout.sandbox_dispatch_enabled', false),
            'shipoutSandboxOwnLabelEnabled' => $warehouseKey === 'us-moreplus'
                && ! app()->environment('production')
                && in_array('6', array_map('trim', explode(',', (string) config('shipout.sandbox_enabled_modes', '9'))), true)
                && (bool) config('shipout.sandbox_dispatch_enabled', false),
            'shipoutSandboxSpecifiedChannel' => $warehouseKey === 'us-moreplus'
                && ! app()->environment('production')
                && in_array('10', array_map('trim', explode(',', (string) config('shipout.sandbox_enabled_modes', '9'))), true)
                && trim((string) config('shipout.sandbox_service_group_id', '')) !== ''
                ? [
                    'id' => trim((string) config('shipout.sandbox_service_group_id')),
                    'label' => trim((string) config('shipout.sandbox_service_group_label', '指定仓库渠道')),
                ] : null,
            'shipoutSandboxTrackingApplyEnabled' => $warehouseKey === 'us-moreplus'
                && ! app()->environment('production')
                && (bool) config('shipout.sandbox_tracking_apply_enabled', false),
            'shipoutSandboxAuthorizedOrderNo' => $warehouseKey === 'us-moreplus'
                ? trim((string) config('shipout.sandbox_order_allowlist', '')) : '',

            'xlwmsBatchEnabled' => $canRunXlwms && (bool) config('xlwms.warehouse_batch_dispatch_enabled', false) && (int) config('xlwms.live_order_id', 0) > 0,
            'viewCounts' => $this->viewCounts($warehouseMap, $selectedWarehouse),
            'canReview' => $this->reviewAccess->allows($request->user()),
            'canArchive' => (bool) ($request->user()?->can('orders.view') && $request->user()?->can('orders.archive')),
            'canOperate' => ($warehouseKey !== 'all' || $view === 'pending')
                && $this->executionAccess->allows($request->user()),
            'canTrackingSync' => app(LogisticsTrackingPushAccess::class)->allows($request->user()),
            'carrierOptions' => $this->carrierServices->options(),
            'trackingImportPreview' => $warehouseKey === 'all' ? null : (collect((array) $request->session()->get($this->trackingSessionKey($warehouseKey), []))->except('normalized_rows')->all() ?: null),
        ]);
    }

    /**
     * Final US-SM V1: export a single manually-importable OMS file, never a
     * provider CREATE. New reference per download, including repeat orders.
     */
    public function exportManualUsSmOrder(int $order): HttpResponse
    {
        $warehouseId = $this->warehouseMap()['us-3sm']['id'] ?? null;
        abort_unless($warehouseId, 404, '世迈仓未配置');
        $shipment = Shipment::query()
            ->with(['order.address', 'order.items', 'warehouse', 'overseaReview'])
            ->where('order_id', $order)->where('warehouse_id', $warehouseId)
            ->firstOrFail();
        $approved = $shipment->overseaReview?->status === 'approved'
            && (int) $shipment->overseaReview->reviewed_warehouse_id === (int) $shipment->warehouse_id;
        abort_unless($approved, 422, '订单尚未通过海外仓审核，请先审核。');
        abort_unless($shipment->order && $shipment->order->order_status?->value !== 'cancelled',
            422, '已取消的 ERP 订单不能导出。');
        abort_if($shipment->order->isOperationallyArchived(), 409, '运营归档订单不可导出发货文件。');
        $file = $this->outboundFiles->exportManualUsSmOrder($shipment);

        return response($file['bytes'], 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function export(Request $request, string $warehouse): HttpResponse
    {
        $warehouse = in_array($warehouse, ['us-sm', 'us-sj', 'usa-sj'], true) ? 'us-3sm' : $warehouse;
        $warehouseMap = $this->warehouseMap();
        $selectedWarehouse = $warehouseMap[$warehouse] ?? null;
        abort_unless($selectedWarehouse, 404, '仓库尚未建立');

        $shipments = Shipment::query()
            ->with(['order.address', 'order.items.product', 'order.manualSync', 'order.fulfillmentParent', 'order.fulfillmentAllocation', 'order.operationalMarkers', 'order.originalFulfillmentChildren.shipment.warehouse', 'order.originalFulfillmentChildren.shipment.overseaReview', 'order.originalFulfillmentChildren.operationalMarkers', 'warehouse', 'overseaReview'])
            ->where('warehouse_id', $selectedWarehouse['id'])
            ->whereHas('order', fn ($q) => $q->eligibleForOperations()
                ->where('order_status', '!=', 'cancelled')
                ->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')));
        $this->applyView($shipments, 'pending');
        try {
            $file = $this->outboundFiles->export($warehouse, $shipments->latest('id')->get());
        } catch (DomainException|InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return response($file['bytes'], 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function previewTrackingReturn(Request $request, string $warehouse): RedirectResponse
    {
        $warehouse = in_array($warehouse, ['us-sm', 'us-sj', 'usa-sj'], true) ? 'us-3sm' : $warehouse;
        abort_unless(array_key_exists($warehouse, OverseaWarehouseReviewService::WAREHOUSE_CODE_BY_KEY), 404);
        $request->validate(['file' => ['required', 'file', 'extensions:xlsx,csv,txt', 'max:10240']]);
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
        $pending = collect($result['sync'])->where('status', 'pending')->count();
        $handoffFailed = collect($result['sync'])->where('status', 'handoff_failed')->count();

        return redirect("/logistics/oversea/{$warehouse}?view=shipped")
            ->with($handoffFailed ? 'warning' : 'success', sprintf(
                'ERP 发货已登记 %d 单，重复跳过 %d 单；官网待同步 %d 单，任务登记异常 %d 单。官网由同步模块每 30 分钟处理，登记异常请管理员核对。',
                $result['applied_count'], $result['noop_count'], $pending, $handoffFailed
            ));
    }

    public function updateDraft(Request $request, Order $order): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['sometimes', 'required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'carrier' => ['sometimes', 'required', 'string', 'max:191', Rule::exists('logistics_carriers', 'name')->where('is_active', true)],
            'service' => ['sometimes', 'nullable', 'string', 'max:191'],
            'tracking_number' => ['sometimes', 'nullable', 'string', 'max:128'],
        ]);
        $movedTracking = false;
        $remark = trim((string) ($data['note'] ?? ''));
        // MorePlus: a strict USPS 22-digit tracking value belongs to Tracking,
        // not free-form review notes. Never alter actual fulfillment status.
        if (preg_match('/\A(?:92|93|94)[0-9]{20}\z/D', $remark) === 1) {
            $order->loadMissing('shipment.warehouse');
            $shipment = $order->shipment;
            if (strcasecmp((string) ($shipment?->warehouse?->code ?? ''), 'US-LA') === 0) {
                abort_unless($this->executionAccess->allows($request->user()), 403,
                    '仅具有发货执行权限的员工可以填写 Tracking');
                abort_unless($shipment?->shipment_status === 'pending', 409,
                    '已发货订单不能通过备注改写 Tracking');
                $existing = trim((string) $shipment->tracking_number);
                abort_if(($existing !== '' && $existing !== $remark)
                    || (isset($data['tracking_number'])
                        && filled($data['tracking_number'])
                        && trim((string) $data['tracking_number']) !== $remark), 409,
                    '订单已有不同 Tracking，禁止用备注覆盖');
                abort_if(DB::table('shipments')->where('tracking_number', $remark)
                    ->where('order_id', '!=', $order->id)->exists(), 409,
                    'Tracking 已关联其他订单，禁止重复使用');
                $data['tracking_number'] = $remark;
                $data['note'] = null;
                $movedTracking = true;
            }
        }

        if (! $this->executionAccess->allows($request->user())) {
            $forbidden = array_diff(array_keys($data), ['warehouse_id', 'note']);
            abort_if($forbidden !== [], 403, '当前账号仅可审核，不可修改发货执行字段');
        }

        $updated = $this->reviews->updateDraft($order, $data, $request->user());

        $completion = null;
        if (filled($data['tracking_number'] ?? null)
            && strcasecmp((string) ($updated->shipment?->warehouse?->code ?? ''), 'US-LA') === 0
            && $this->executionAccess->allows($request->user())) {
            // One independent signed GET only for MorePlus: exact OMS Shipped
            // and physical outbound proof. Never write to ShipOut.
            $completion = app(ShipOutMorePlusVerifiedTrackingCompletion::class)
                ->tryComplete($updated, $request->user());
        }
        $latest = $updated->fresh(['shipment', 'shipment.overseaReview']);
        $saved = $latest->shipment;
        $message = $completion && $completion['status'] === 'shipped'
            ? '仓库真实出库凭证已核实，ERP 已发货；官网由同步模块定时处理'
            : ($movedTracking
                ? '已将 USPS 单号自动归位 Tracking No.；仍待真实出库核实'
                : '字段已保存，无需刷新页面');

        // Axios inline edit: one tiny JSON response, no Inertia redirect,
        // no full order-list SQL query, and no page reload.
        if ($request->expectsJson()) {
            return response()->json([
                'order_id' => (int) $latest->id,
                'shipment_status' => $saved?->shipment_status,
                'tracking_number' => $saved?->tracking_number,
                'carrier' => $saved?->carrier,
                'service' => $saved?->service,
                'note' => $saved?->overseaReview?->note,
                'auto_shipped' => $completion && $completion['status'] === 'shipped',
                'website_sync' => $completion['website_sync'] ?? null,
                'tracking_moved' => $movedTracking,
                'message' => $message,
            ])->header('Cache-Control', 'no-store, private');
        }

        return back()->with('success', $message);
    }

    /** The same review privileges as single-order actions; each row validates independently. */
    public function batchReview(Request $request): JsonResponse
    {
        abort_unless($this->reviewAccess->allows($request->user()), 403);
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:50'],
            'order_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'action' => ['required', Rule::in(['approve', 'stockout', 'reject', 'restock'])],
            'reason' => ['required_if:action,reject', 'nullable', 'string', 'min:3', 'max:500'],
        ]);
        $results = [];
        foreach ($data['order_ids'] as $orderId) {
            $id = (int) $orderId;
            try {
                $order = Order::query()->findOrFail($id);
                match ($data['action']) {
                    'approve' => $this->reviews->approve($order, $request->user()),
                    'stockout' => $this->reviews->stockout($order, $request->user()),
                    'restock' => $this->reviews->restock($order, $request->user()),
                    'reject' => $this->reviews->reject($order, trim($data['reason']), $request->user()),
                };
                $results[] = ['order_id' => $id, 'ok' => true, 'message' => '已处理'];
            } catch (HttpExceptionInterface $e) {
                $results[] = ['order_id' => $id, 'ok' => false, 'message' => $e->getStatusCode() === 403 ? '无操作权限' : ($e->getMessage() ?: '状态已变化')];
            } catch (ModelNotFoundException $e) {
                $results[] = ['order_id' => $id, 'ok' => false, 'message' => '订单不存在'];
            } catch (DomainException|InvalidArgumentException $e) {
                $results[] = ['order_id' => $id, 'ok' => false, 'message' => '业务状态不允许操作'];
            }
        }

        return response()->json([
            'results' => $results,
            'success_count' => count(array_filter($results, fn (array $r): bool => $r['ok'])),
            'failed_count' => count(array_filter($results, fn (array $r): bool => ! $r['ok'])),
        ]);
    }

    public function stockout(Request $request, Order $order): RedirectResponse
    {
        abort_unless($this->reviewAccess->allows($request->user()), 403);
        $this->reviews->stockout($order, $request->user());

        return back()->with('success', '订单已标记缺货，禁止发货。');
    }

    public function restock(Request $request, Order $order): RedirectResponse
    {
        abort_unless($this->reviewAccess->allows($request->user()), 403);
        $this->reviews->restock($order, $request->user());

        return back()->with('success', '已确认到货并审核通过，进入待发货。');
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
        $code = OverseaWarehouseReviewService::canonicalWarehouseCode($reopened->shipment?->warehouse?->code);
        $key = array_search($code, OverseaWarehouseReviewService::WAREHOUSE_CODE_BY_KEY, true);
        abort_unless(is_string($key), 422, '该订单不是海外仓订单');

        return redirect("/logistics/oversea/{$key}?view=review")->with('success', '订单已重新进入审核，可重新分配仓库');
    }

    public function reject(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']]);
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
            ->get()
            ->filter(fn (Warehouse $warehouse): bool => OverseaWarehouseReviewService::canonicalWarehouseCode($warehouse->code) !== null)
            ->keyBy(fn (Warehouse $warehouse): string => (string) OverseaWarehouseReviewService::canonicalWarehouseCode($warehouse->code));

        return collect(OverseaWarehouseReviewService::WAREHOUSE_CODE_BY_KEY)
            ->mapWithKeys(function (string $code, string $key) use ($byCode): array {
                $warehouse = $byCode->get($code);
                if (! $warehouse) {
                    return [];
                }

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
            $this->progressQueries->overseasReview($query);

            return;
        }
        if ($view === 'stockout') {
            $this->progressQueries->overseasStockout($query);

            return;
        }
        if ($view === 'pending') {
            $this->progressQueries->overseasReady($query)
                ->where(function ($warehouses): void {
                    $warehouses->whereHas('warehouse', fn ($w) => $w->whereNotIn('code', ['US-LA', 'US-SM']))
                        ->orWhere(function ($sm): void {
                            $sm->whereHas('warehouse', fn ($w) => $w->where('code', 'US-SM'))
                                ->whereNotExists(fn ($q) => $this->scope3smIssued($q))
                                ->whereNotExists(fn ($q) => $this->scope3smException($q));
                        })->orWhere(function ($moreplus): void {
                            $moreplus->whereHas('warehouse', fn ($w) => $w->where('code', 'US-LA'))
                                ->whereNotExists(fn ($sub) => ShipOutProductionDispatchState::scopeCreated($sub))
                                ->whereNotExists(fn ($sub) => ShipOutProductionDispatchState::scopeOmsException($sub))
                                ->whereNotExists(fn ($sub) => ShipOutProductionDispatchState::scopeBatchQuarantined($sub))
                                ->whereNotExists(function ($sub): void {
                                    ShipOutProductionDispatchState::scopeAttempt($sub);
                                    $sub->where('shipout_attempt.status', '!=', 'acknowledged');
                                });
                        });
                });

            return;
        }
        if ($view === 'issued') {
            $query->where('shipment_status', 'pending')
                ->whereHas('overseaReview', fn ($r) => $r
                    ->where('status', 'approved')
                    ->whereColumn('reviewed_warehouse_id', 'shipments.warehouse_id'))
                ->where(function ($byWarehouse): void {
                    $byWarehouse->where(function ($sm): void {
                        $sm->whereHas('warehouse', fn ($w) => $w->where('code', 'US-SM'))
                            ->whereExists(fn ($sub) => $this->scope3smIssued($sub));
                    })->orWhere(function ($moreplus): void {
                        $moreplus->whereHas('warehouse', fn ($w) => $w->where('code', 'US-LA'))
                            ->whereExists(fn ($sub) => ShipOutProductionDispatchState::scopeCreated($sub))
                            ->whereNotExists(fn ($sub) => ShipOutProductionDispatchState::scopeBatchQuarantined($sub));
                    });
                });

            return;
        }
        if ($view === 'shipped') {
            $query->where('shipment_status', 'shipped');

            return;
        }
        if ($view === 'exceptions') {
            $query->where(function ($exceptions): void {
                $exceptions->where('shipment_status', 'exception')
                    ->orWhere(function ($sm): void {
                        $sm->where('shipment_status', 'pending')
                            ->whereHas('warehouse', fn ($w) => $w->where('code', 'US-SM'))
                            ->whereExists(fn ($q) => $this->scope3smException($q));
                    })
                    ->orWhere(function ($unresolved): void {
                        $unresolved->where('shipment_status', 'pending')
                            ->whereHas('warehouse', fn ($w) => $w->where('code', 'US-LA'))
                            ->where(function ($problem): void {
                                $problem
                                    ->whereExists(fn ($sub) => ShipOutProductionDispatchState::scopeOmsException($sub))
                                    ->orWhereExists(function ($sub): void {
                                        ShipOutProductionDispatchState::scopeAttempt($sub);
                                        $sub->where(function ($notConfirmed): void {
                                            $notConfirmed->where('shipout_attempt.status', '!=', 'acknowledged')
                                                ->orWhereNull('shipout_attempt.provider_order_id')
                                                ->orWhere('shipout_attempt.provider_order_id', '');
                                        });
                                    })
                                    ->orWhereExists(fn ($sub) => ShipOutProductionDispatchState::scopeBatchQuarantined($sub));
                            });
                    });
            });
        }
    }

    /** 3SM: an OMS draft (status 0) is not a warehouse-dispatched order. */
    private function scope3smIssued(Builder $query): void
    {
        $query->selectRaw('1')->from('xlwms_dispatch_attempts as sm_issued')
            ->whereColumn('sm_issued.order_id', 'shipments.order_id')
            ->whereNotNull('sm_issued.oms_order_no')
            ->where('sm_issued.oms_order_no', '!=', '')
            ->whereIn('sm_issued.oms_status_code', ['2', '3'])
            ->whereNotIn('sm_issued.state', ['oms_draft_unverified', 'batch_draft_unverified']);
    }

    private function scope3smException(Builder $query): void
    {
        $query->selectRaw('1')->from('xlwms_dispatch_attempts as sm_exception')
            ->whereColumn('sm_exception.order_id', 'shipments.order_id')
            ->where(function ($exception): void {
                $exception->whereIn('sm_exception.oms_status_code', ['4', '5'])
                    ->orWhereIn('sm_exception.state', ['oms_draft_unverified', 'batch_draft_unverified']);
            });
    }

    private function viewCounts(array $warehouseMap, ?array $selectedWarehouse): array
    {
        $counts = [];
        foreach (self::VIEWS as $view) {
            $query = Shipment::query()->whereIn('warehouse_id', collect($warehouseMap)->pluck('id')->all())
                ->whereHas('order', fn ($q) => $q->eligibleForOperations()->where('order_status', '!=', 'cancelled')
                    ->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')));
            if ($selectedWarehouse) {
                $query->where('warehouse_id', $selectedWarehouse['id']);
            }
            $this->applyView($query, $view);
            $counts[$view] = $query->count();
        }

        return $counts;
    }

    private function row(Shipment $shipment, int $line, ?XlwmsDispatchAttempt $xlwms = null, ?array $tracking = null, ?XlwmsWebDraftAttempt $webDraft = null, ?object $shipOutAttempt = null, ?object $shipOutBatchItem = null, ?object $shipOutReadback = null): array
    {
        $order = $shipment->order;
        $trackingState = app(LogisticsTrackingListStatus::class)
            ->currentStatus($tracking, $order?->manualSync?->website_sync_status);
        $review = $shipment->overseaReview;
        $effectiveApproved = $review?->status === 'approved'
            && (int) $review->reviewed_warehouse_id === (int) $shipment->warehouse_id;

        return [
            'line' => $line,
            'order_id' => $order?->id,
            'erp_order_id' => $order?->erp_order_id,
            'order_url' => $order ? "/orders/{$order->id}" : null,
            'recipient_name' => $order?->address?->recipient_name,
            'order_date' => $order?->order_date?->toDateString(),
            'country' => $order?->address?->country,
            'warehouse_id' => $shipment->warehouse_id,
            'warehouse_code' => $shipment->warehouse?->code,
            'warehouse_name' => $shipment->warehouse?->name,
            'carrier' => $shipment->carrier ?: OverseaWarehouseReviewService::carrierForWarehouseCode($shipment->warehouse?->code),
            'service' => $shipment->service,
            'carrier_selection_source' => $shipment->carrier_selection_source,
            'tracking_number' => $shipment->tracking_number,
            'progress' => $review?->progress ?? 'pending_review',
            'note' => $review?->note,
            'reviewer_name' => $review?->reviewer?->name,
            'reviewed_at' => $review?->reviewed_at?->copy()->timezone('Asia/Shanghai')->format('Y-m-d H:i'),
            'review_status' => $effectiveApproved ? 'approved'
                : ($review?->status === 'stockout'
                    && (int) $review->reviewed_warehouse_id === (int) $shipment->warehouse_id ? 'stockout' : 'pending'),
            'shipment_status' => $shipment->shipment_status,
            'workflow_status_projection' => $order ? $this->workflowStatusProjection->for($order) : null,
            'workflow_revision' => (int) ($order?->workflow_revision ?? 0),
            'warehouse_dispatch' => $shipment->warehouse?->code === 'US-LA'
                ? ShipOutProductionDispatchState::warehouseDispatch($shipOutAttempt, (string) $shipment->shipment_status, $shipOutBatchItem, $shipOutReadback)
                : WarehouseDispatchStatus::from((string) $shipment->shipment_status, $xlwms),
            'shipout_production' => $shipment->warehouse?->code === 'US-LA'
                ? ShipOutProductionDispatchState::from($shipOutAttempt, $shipOutBatchItem, $shipOutReadback) : null,
            'source_type' => ($order?->fulfillment_parent_id ? $order->fulfillmentParent?->source_type : $order?->source_type) instanceof \BackedEnum
                ? ($order?->fulfillment_parent_id ? $order->fulfillmentParent?->source_type : $order?->source_type)->value
                : (string) ($order?->fulfillment_parent_id ? $order->fulfillmentParent?->source_type : $order?->source_type),
            'tracking_sync_status' => $trackingState['status'],
            'tracking_sync_reason' => $trackingState['reason'],
            'tracking_sync_verified_at' => $trackingState['verified_at'] ?? null,
            'shipped_at' => $shipment->shipped_at?->toIso8601String(),
            'xlwms' => $xlwms ? [
                'state' => $xlwms->state,
                'oms_order_no' => $xlwms->oms_order_no,
                'oms_status_code' => $xlwms->oms_status_code,
                'oms_status_checked_at' => $xlwms->oms_status_checked_at,
                'oms_tracking_no' => $xlwms->oms_tracking_no,
                'failure_code' => $xlwms->failure_code,
                'oms_status_failure_code' => $xlwms->oms_status_failure_code,
            ] : null,
            'web_draft' => $webDraft ? [
                'state' => $webDraft->state, 'oms_order_no' => $webDraft->oms_order_no,
                'attempt_ref' => $webDraft->attempt_ref, 'failure_code' => $webDraft->failure_code,
            ] : null,

            'items' => $order?->items->map(fn ($item): array => [
                'sku' => WarehouseSkuPresentation::sku($item),
                'source_sku' => $item->source_sku ?: $item->sku,
                'quantity' => (int) $item->quantity,
            ])->values()->all() ?? [],
        ];
    }
}
