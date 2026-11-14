<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Inventory\Infrastructure\Models\InventoryReservation;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\AllocationRejectionReadModel;
use App\Modules\Logistics\Application\AssistedFulfillmentService;
use App\Modules\Logistics\Application\AutomaticAllocationEligibilityService;
use App\Modules\Logistics\Application\CarrierRoutingPolicy;
use App\Modules\Logistics\Application\CarrierServiceCatalog;
use App\Modules\Logistics\Application\FulfillmentAllocationAccess;
use App\Modules\Logistics\Application\FulfillmentAllocationDecisionService;
use App\Modules\Logistics\Application\FulfillmentAllocationSubmissionService;
use App\Modules\Logistics\Application\FulfillmentPlanPreviewService;
use App\Modules\Logistics\Application\GiftSkuResolutionService;
use App\Modules\Logistics\Application\LogisticsExecutionAccess;
use App\Modules\Logistics\Application\LogisticsStatusSummary;
use App\Modules\Logistics\Application\PendingFulfillmentReallocationService;
use App\Modules\Logistics\Application\ReplacementFulfillmentService;
use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Logistics\Application\ShipmentWebsiteSyncHandoff;
use App\Modules\Logistics\Application\UsFulfillmentZoneResolver;
use App\Modules\Logistics\Application\WarehouseRecommendationService;
use App\Modules\Logistics\Application\WarehouseSkuPresentation;
use App\Modules\Logistics\Infrastructure\Models\LogisticsReview;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Application\DomesticReviewAmountResolver;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderPackagingReferenceProjection;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Shell\Application\GlobalGridPilot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class LogisticsController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly ReplacementFulfillmentService $replacementFulfillments,
        private readonly PendingFulfillmentReallocationService $pendingReallocation,
        private readonly AutomaticAllocationEligibilityService $automaticAllocationEligibility,
        private readonly AssistedFulfillmentService $assistedFulfillment,
        private readonly WarehouseRecommendationService $recommendations,
        private readonly FulfillmentPlanPreviewService $fulfillmentPlans,
        private readonly FulfillmentAllocationDecisionService $allocationDecisions,
        private readonly FulfillmentAllocationSubmissionService $allocationSubmissions,
        private readonly FulfillmentAllocationAccess $allocationAccess,
        private readonly GiftSkuResolutionService $giftSkuResolutions,
        private readonly LogisticsExecutionAccess $executionAccess,
        private readonly LogisticsStatusSummary $statusSummary,
        private readonly AllocationRejectionReadModel $rejectionReadModel,
        private readonly UsFulfillmentZoneResolver $usZones,
        private readonly CarrierRoutingPolicy $carrierRouting,
        private readonly CarrierServiceCatalog $carrierServices,
        private readonly OrderPackagingReferenceProjection $packagingReferences,
    ) {}

    public function waitStock(Request $request): Response
    {
        // This page has graduated from the Owner-only pilot. Legacy stays available via ?global_grid=0.
        $gridPilot = $request->query('global_grid') !== '0';
        $search = $gridPilot ? trim(mb_substr((string) $request->query('search', ''), 0, 100)) : '';
        $rejectionFilter = $request->query('rejected') === '1';
        $rejectionSnapshot = $this->rejectionReadModel->snapshot(
            $this->statusSummary->pendingAllocationQuery()
        );
        $rejectionCount = $rejectionSnapshot['count'];
        $query = $this->statusSummary->pendingAllocationQuery()
            ->with(['address', 'items.product', 'owner', 'financials', 'shipment.warehouse',
                'fulfillmentAllocation', 'externalReferences:id,order_id,provider',
                'domesticRejecter:id,name', 'overseaWarehouseReview.reviewer:id,name']);
        if ($rejectionFilter) {
            $this->rejectionReadModel->onlyRejected($query);
        }
        if ($search !== '') {
            $term = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($builder) use ($term): void {
                $builder->where('erp_order_id', 'like', $term)
                    ->orWhere('external_order_number', 'like', $term)
                    ->orWhereHas('address', fn ($address) => $address->where('recipient_name', 'like', $term));
            });
        }
        $requestedSize = (int) $request->query('per_page', self::PER_PAGE);
        $pageSize = $gridPilot && in_array($requestedSize, [20, 50, 100], true) ? $requestedSize : self::PER_PAGE;
        $orders = $query->latest('order_date')->latest('id')
            ->paginate($pageSize)->withQueryString();

        $productIds = collect($orders->items())->flatMap(fn (Order $order) => $order->items->pluck('product_id'))->filter()->unique()->values()->all();
        $context = array_replace($this->recommendations->prepare($productIds), $this->fulfillmentPlans->prepare($productIds));
        $context['gift_resolution'] = $this->giftSkuResolutions->prepare();
        $activeReservations = InventoryReservation::query()
            ->whereIn('order_id', collect($orders->items())->pluck('id')->all())
            ->where('status', 'reserved')
            ->get()
            ->keyBy('order_id')
            ->all();
        $paymentDecisions = $this->automaticAllocationEligibility->preparePaymentDecisions($orders->items());
        $allowedCodes = array_map('strtoupper', (array) config('warehouse_master.fulfillment_codes', []));
        $warehouses = collect($context['warehouses'] ?? [])
            ->filter(fn (array $warehouse): bool => in_array(strtoupper((string) ($warehouse['code'] ?? '')), $allowedCodes, true))
            ->values()->all();

        return Inertia::render('Logistics/FulfillmentAllocation', [
            'orders' => $orders->through(fn (Order $order): array => $this->data($order, $context, $activeReservations, $paymentDecisions))->toArray(),
            'warehouses' => $warehouses,
            'carrierOptions' => $this->carrierServices->options(),
            'pendingTotal' => $rejectionFilter ? $this->statusSummary->waitStockCount() : $orders->total(),
            'rejectionCount' => $rejectionCount,
            'rejectionNoticeVersion' => $rejectionSnapshot['notice_version'],
            'rejectionFilter' => $rejectionFilter,
            'canOperate' => $this->allocationAccess->allows($request->user()),
            'canArchive' => (bool) ($request->user()?->can('orders.view') && $request->user()?->can('orders.archive')),
            'globalGridPilotAvailable' => true,
            'globalGridPilotEnabled' => $gridPilot,
            'gridSearch' => $search,
        ]);
    }

    /**
     * Batch operations reuse exactly the single-order allocation service, including
     * payment, current recommendation, warehouse, gift and stock guards. A failed
     * item is isolated; a successful item must never be submitted twice.
     */
    public function batchAllocate(Request $request): JsonResponse
    {
        $allowedWarehouseCodes = array_map('strtoupper', (array) config('warehouse_master.fulfillment_codes', []));
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:100'],
            'order_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'action' => ['required', Rule::in(['recommend', 'warehouse'])],
            'warehouse_code' => ['required_if:action,warehouse', 'nullable', 'string', Rule::in($allowedWarehouseCodes)],
            'plan_keys' => ['sometimes', 'array'],
            'plan_keys.*' => ['string', 'max:128'],
            'confirmed' => ['required', 'accepted'],
        ]);

        $warehouseCode = strtoupper(trim((string) ($data['warehouse_code'] ?? '')));
        if ($data['action'] === 'warehouse') {
            abort_unless(Warehouse::query()->where('code', $warehouseCode)->where('is_active', true)->exists(), 422, '批量目标仓库未启用。');
        }

        $orders = $this->statusSummary->pendingAllocationQuery()
            ->whereIn('orders.id', $data['order_ids'])
            ->with(['items', 'fulfillmentAllocation'])
            ->get()->keyBy('id');
        $results = [];

        foreach ($data['order_ids'] as $rawId) {
            $id = (int) $rawId;
            $order = $orders->get($id);
            if (! $order) {
                $results[] = ['order_id' => $id, 'ok' => false, 'message' => '订单不在待分仓范围，请刷新列表。'];

                continue;
            }
            if ($order->fulfillmentAllocation?->status === 'submitted') {
                $results[] = ['order_id' => $id, 'ok' => false, 'message' => '该订单已完成分仓，未重复提交。'];

                continue;
            }

            try {
                // The outer row lock serializes concurrent bulk clicks. Recheck
                // submission state after taking the lock, not just in the list.
                DB::transaction(function () use ($order, $id, $data, $warehouseCode, $request): void {
                    $locked = Order::query()->with(['items', 'fulfillmentAllocation'])
                        ->lockForUpdate()->findOrFail($order->id);
                    abort_if($locked->fulfillmentAllocation?->status === 'submitted', 409, '该订单已完成分仓，未重复提交。');

                    if ($data['action'] === 'recommend') {
                        $planKey = trim((string) ($data['plan_keys'][(string) $id] ?? ''));
                        abort_if($planKey === '', 422, '本订单无可确认的系统推荐，请人工分仓。');
                        $this->allocationSubmissions->submitRecommended($locked, $planKey, null, $request->user());
                    } else {
                        abort_if($locked->items->isEmpty() || $locked->items->contains(
                            fn ($item): bool => strcasecmp(trim((string) ($item->source_sku ?: $item->sku)), 'gift') === 0
                        ), 422, '礼品或空 SKU 订单需逐单确认，已跳过。');
                        $assignments = $locked->items->map(fn ($item): array => [
                            'order_item_id' => (int) $item->id,
                            'source_code' => $warehouseCode,
                        ])->values()->all();
                        // A bulk click cannot silently acknowledge missing/short stock.
                        $this->allocationSubmissions->submitManual($locked, $assignments, null, $request->user(), false);
                    }
                });
                $results[] = ['order_id' => $id, 'ok' => true, 'message' => '已完成分仓'];
            } catch (ValidationException $exception) {
                $message = collect($exception->errors())->flatten()->first() ?: '订单条件未通过，请单独确认。';
                $results[] = ['order_id' => $id, 'ok' => false, 'message' => (string) $message];
            } catch (HttpResponseException $exception) {
                $responseBody = json_decode((string) $exception->getResponse()->getContent(), true);
                $results[] = ['order_id' => $id, 'ok' => false, 'message' => (string) ($responseBody['message'] ?? '库存条件未通过，请单独确认。')];
            } catch (HttpException $exception) {
                $results[] = ['order_id' => $id, 'ok' => false, 'message' => $exception->getMessage() ?: '订单状态不允许分仓。'];
            } catch (Throwable $exception) {
                report($exception);
                $results[] = ['order_id' => $id, 'ok' => false, 'message' => '服务器未完成此订单，请核对后再处理。'];
            }
        }

        return response()->json([
            'results' => $results,
            'succeeded' => collect($results)->where('ok', true)->count(),
            'failed' => collect($results)->where('ok', false)->count(),
        ]);
    }

    public function submitRecommendedAllocation(Request $request, Order $order): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'plan_key' => ['required', 'string', 'max:128'],
            'decision_note' => ['nullable', 'string', 'max:1000'],
        ]);
        $allocation = $this->submitAllocationForInertia($request, fn () => $this->allocationSubmissions->submitRecommended($order, $data['plan_key'], $data['decision_note'] ?? null, $request->user()));
        if ($request->expectsJson()) {
            return response()->json(['status' => $allocation->status, 'decision_source' => $allocation->decision_source, 'submitted_at' => $allocation->submitted_at?->toIso8601String()]);
        }

        return back()->with('success', '已采用系统推荐并提交分仓');
    }

    public function submitManualAllocation(Request $request, Order $order): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*.order_item_id' => ['required', 'integer'],
            'assignments.*.source_code' => ['required', 'string', 'max:32'],
            'assignments.*.gift_mapping_id' => ['nullable', 'integer'],
            'assignments.*.carrier' => ['nullable', 'string', 'max:128', Rule::exists('logistics_carriers', 'name')->where('is_active', true)],
            'assignments.*.service' => ['nullable', 'string', 'max:191'],
            'decision_note' => ['nullable', 'string', 'max:1000'],
            'stock_exception_acknowledged' => ['sometimes', 'boolean'],
        ]);
        $allocation = $this->submitAllocationForInertia($request, fn () => $this->allocationSubmissions->submitManual($order, $data['assignments'], $data['decision_note'] ?? null, $request->user(), (bool) ($data['stock_exception_acknowledged'] ?? false)));
        if ($request->expectsJson()) {
            return response()->json(['status' => $allocation->status, 'decision_source' => $allocation->decision_source, 'submitted_at' => $allocation->submitted_at?->toIso8601String()]);
        }

        return back()->with('success', '人工分仓方案已提交');
    }

    public function submitQuickAllocation(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*.order_item_id' => ['required', 'integer'],
            'assignments.*.warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'stock_exception_acknowledged' => ['sometimes', 'boolean'],
        ]);

        $order->loadMissing(['address', 'items']);
        if ($order->items->contains(fn ($item): bool => strcasecmp(trim((string) ($item->source_sku ?: $item->sku)), 'gift') === 0)) {
            throw ValidationException::withMessages(['assignments' => 'gift 订单需要在“待分仓”中选择实际履约礼品 SKU。']);
        }

        $warehouseIds = collect($data['assignments'])->pluck('warehouse_id')->map(fn ($id): int => (int) $id)->unique()->values();
        $warehouses = Warehouse::query()->whereIn('id', $warehouseIds)->where('is_active', true)->get()->keyBy('id');
        $allowedCodes = array_map('strtoupper', (array) config('warehouse_master.fulfillment_codes', []));
        $normalized = collect($data['assignments'])->map(function (array $assignment) use ($order, $warehouses, $allowedCodes): array {
            $warehouse = $warehouses->get((int) $assignment['warehouse_id']);
            if (! $warehouse || ! in_array(strtoupper((string) $warehouse->code), $allowedCodes, true)) {
                throw ValidationException::withMessages(['assignments' => '所选仓库不属于待分仓履约仓库。']);
            }
            $route = strtoupper(trim((string) $warehouse->code)) === 'FACTORY'
                ? [] : $this->carrierRouting->warehouseRoute($order->address?->country, (string) $warehouse->code);
            $carrier = trim((string) ($route['carrier'] ?? ''));
            if ($carrier === '' && strtoupper(trim((string) $warehouse->code)) !== 'FACTORY') {
                throw ValidationException::withMessages(['assignments' => "{$warehouse->name} 尚未配置默认物流商，请进入完整待分仓流程。"]);
            }

            return [
                'order_item_id' => (int) $assignment['order_item_id'],
                'source_code' => (string) $warehouse->code,
                'gift_mapping_id' => null,
                'carrier' => $carrier,
                'service' => isset($route['service']) && trim((string) $route['service']) !== '' ? trim((string) $route['service']) : null,
            ];
        })->values()->all();

        $this->submitAllocationForInertia($request, fn () => $this->allocationSubmissions->submitManual($order, $normalized, null, $request->user(), (bool) ($data['stock_exception_acknowledged'] ?? false)));

        return back()->with('success', '分仓已提交');
    }

    /**
     * Inertia expects business validation failures as redirect errors, not a raw 422 HTML error page.
     * JSON consumers retain their existing HTTP error status and payload.
     */
    private function submitAllocationForInertia(Request $request, callable $submit): mixed
    {
        try {
            return $submit();
        } catch (HttpException $exception) {
            if (! $request->header('X-Inertia') || ! in_array($exception->getStatusCode(), [409, 422], true)) {
                throw $exception;
            }

            $reason = trim($exception->getMessage());
            throw ValidationException::withMessages([
                'assignments' => $reason !== '' ? $reason : '分仓未提交，请核实仓库和订单状态。',
            ]);
        }
    }

    public function saveDraft(Request $request, Order $order): RedirectResponse
    {
        $this->assistedFulfillment->saveDraft($order, $request->validate($this->draftRules()), $request->user());

        return back()->with('success', '履约草稿已保存');
    }

    public function adoptRecommendation(Request $request, Order $order): RedirectResponse|JsonResponse
    {
        $result = $this->assistedFulfillment->adoptRecommendation($order, $request->user());

        if ($request->expectsJson()) {
            $shipment = $result->shipment;

            return response()->json([
                'warehouse_id' => $shipment?->warehouse_id,
                'warehouse_name' => $shipment?->warehouse?->name,
                'carrier' => $shipment?->carrier,
                'service' => $shipment?->service,
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
            ->whereHas('order', fn ($q) => $q->eligibleForOperations()->where('order_status', '!=', 'cancelled')->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')));
        $grid = $this->l2GridSettings($request, $shipments);
        $shipments = $shipments->paginate($grid['pageSize'])->withQueryString();

        return Inertia::render('Logistics/AwaitingTracking', [
            'shipments' => $shipments->through(fn ($s) => $this->queueRow($s))->toArray(),
            'statusBar' => $this->statusSummary->statusBar(),
            'canOperate' => $this->executionAccess->allows($request->user()),
            'globalGridPilotAvailable' => $grid['available'],
            'globalGridPilotEnabled' => $grid['enabled'],
            'gridSearch' => $grid['search'],
            'gridSort' => $grid['sort'],
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
            ->whereHas('order', fn ($q) => $q->eligibleForOperations()->where('order_status', '!=', 'cancelled')->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')));
        $grid = $this->l2GridSettings($request, $shipments);
        $shipments = $shipments->paginate($grid['pageSize'])->withQueryString();

        return Inertia::render('Logistics/InTransit', [
            'shipments' => $shipments->through(fn ($s) => $this->queueRow($s))->toArray(),
            'statusBar' => $this->statusSummary->statusBar(),
            'canOperate' => $this->executionAccess->allows($request->user()),
            'globalGridPilotAvailable' => $grid['available'],
            'globalGridPilotEnabled' => $grid['enabled'],
            'gridSearch' => $grid['search'],
            'gridSort' => $grid['sort'],
        ]);
    }

    public function exceptions(Request $request): Response
    {
        $shipments = Shipment::query()
            ->with(['order.address', 'order.items.product', 'order.logisticsReview', 'warehouse'])
            ->where('shipment_status', 'exception')
            ->whereDoesntHave('order.logisticsReview', fn ($q) => $q->where('status', 'pending'))
            ->whereHas('order', fn ($q) => $q->eligibleForOperations()->where('order_status', '!=', 'cancelled')->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')));
        $grid = $this->l2GridSettings($request, $shipments);
        $shipments = $shipments->paginate($grid['pageSize'])->withQueryString();

        return Inertia::render('Logistics/Exceptions', [
            'shipments' => $shipments->through(fn ($s) => $this->queueRow($s))->toArray(),
            'statusBar' => $this->statusSummary->statusBar(),
            'canOperate' => $this->executionAccess->allows($request->user()),
            'globalGridPilotAvailable' => $grid['available'],
            'globalGridPilotEnabled' => $grid['enabled'],
            'gridSearch' => $grid['search'],
            'gridSort' => $grid['sort'],
        ]);
    }

    /**
     * Three L2 logistics queues share exactly one server-authoritative
     * search / pagination / sorting contract. All queue inclusion predicates
     * are applied BEFORE this read-only adapter and remain unchanged.
     */
    private function l2GridSettings(Request $request, Builder $query): array
    {
        $available = GlobalGridPilot::available();
        $enabled = GlobalGridPilot::enabled($request);
        $search = $enabled ? trim(mb_substr((string) $request->query('search', ''), 0, 100)) : '';
        if ($search !== '') {
            $term = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn ($scope) => $scope
                ->where('tracking_number', 'like', $term)
                ->orWhere('logistics_note', 'like', $term)
                ->orWhere('carrier', 'like', $term)
                ->orWhere('service', 'like', $term)
                ->orWhereHas('order', fn ($order) => $order
                    ->where('erp_order_id', 'like', $term)
                    ->orWhere('external_order_number', 'like', $term)
                    ->orWhereHas('address', fn ($address) => $address
                        ->where('recipient_name', 'like', $term))));
        }

        // Sort only fields on the shipments table. No untrusted SQL columns.
        $sortMap = [
            'shipped_at' => 'shipped_at',
            'carrier' => 'carrier',
            'tracking_number' => 'tracking_number',
        ];
        $sort = $enabled ? (string) $request->query('sort', '') : '';
        $direction = strtolower((string) $request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        if ($enabled && isset($sortMap[$sort])) {
            $query->reorder()->orderBy($sortMap[$sort], $direction)->orderByDesc('id');
        } else {
            $query->latest('shipped_at')->latest('id');
            $sort = 'shipped_at';
            $direction = 'desc';
        }

        $requestedSize = (int) $request->query('per_page', self::PER_PAGE);
        $pageSize = $enabled && in_array($requestedSize, [20, 50, 100], true)
            ? $requestedSize : self::PER_PAGE;

        return [
            'available' => $available,
            'enabled' => $enabled,
            'search' => $search,
            'sort' => ['colId' => $sort, 'direction' => $direction],
            'pageSize' => $pageSize,
        ];
    }

    public function review(Request $request): Response
    {
        $gridPilot = GlobalGridPilot::enabled($request);
        $search = $gridPilot ? trim(mb_substr((string) $request->query('search', ''), 0, 100)) : '';
        $query = LogisticsReview::query()->with(['order.address', 'order.items.product', 'order.shipment.warehouse'])
            ->where('status', 'pending')->whereHas('order', fn ($q) => $q->eligibleForOperations()->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')));
        if ($search !== '') {
            $term = '%'.addcslashes($search, '%_\\').'%';
            $query->whereHas('order', fn ($order) => $order
                ->where(fn ($q) => $q->where('erp_order_id', 'like', $term)
                    ->orWhere('external_order_number', 'like', $term)
                    ->orWhereHas('address', fn ($address) => $address->where('recipient_name', 'like', $term))));
        }
        $requestedSize = (int) $request->query('per_page', self::PER_PAGE);
        $pageSize = $gridPilot && in_array($requestedSize, [20, 50, 100], true) ? $requestedSize : self::PER_PAGE;
        $reviews = $query->latest('requested_at')->paginate($pageSize)->withQueryString();

        return Inertia::render('Logistics/Review', [
            'reviews' => $reviews->through(fn (LogisticsReview $review): array => [...$this->queueRow($review->order->shipment), 'review_id' => $review->id, 'reason' => $review->reason, 'trigger' => $review->trigger, 'requested_at' => $review->requested_at?->toIso8601String()])->toArray(),
            'statusBar' => $this->statusSummary->statusBar(),
            'canOperate' => $this->executionAccess->allows($request->user()),
            'globalGridPilotAvailable' => GlobalGridPilot::available(),
            'globalGridPilotEnabled' => $gridPilot,
            'gridSearch' => $search,
        ]);
    }

    public function completed(Request $request): Response
    {
        return $this->projection($request, 'completed', 'Logistics/Completed', fn ($q) => $q->where('shipment_status', 'completed'));
    }

    public function cancelled(Request $request): Response
    {
        $orders = Order::query()->eligibleForOperations()->with(['address', 'items.product', 'shipment.warehouse'])->where('order_status', 'cancelled')->whereDoesntHave('externalReferences', fn ($q) => $q->where('provider', 'historical_logistics'))->latest('order_date')->latest('id')->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('Logistics/Cancelled', ['orders' => $orders->through(fn (Order $order) => $this->orderProjection($order))->toArray(), 'statusBar' => $this->statusSummary->statusBar()]);
    }

    public function decideReview(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['destination' => ['required', 'string'], 'decision_note' => ['required', 'string', 'max:5000']]);
        $this->shipments->decideReview($order, $data['destination'], $data['decision_note'], $request->user());

        return back()->with('success', '复核已处理');
    }

    public function pause(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['logistics_note' => ['required', 'string', 'max:5000']]);
        $this->shipments->pauseException($order, $data['logistics_note'], $request->user());

        return back()->with('success', '订单已暂停');
    }

    public function redispatch(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['logistics_note' => ['required', 'string', 'max:5000']]);
        $this->shipments->requestReview($order, 'redispatch', $data['logistics_note'], $request->user());

        return back()->with('success', '重新派件已提交复核');
    }

    public function reship(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['logistics_note' => ['required', 'string', 'max:5000']]);
        $this->shipments->reship($order, $data, $request->user());

        return back()->with('success', '已保留原始履约证据并重置待发货');
    }

    public function contactUser(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['logistics_note' => ['required', 'string', 'max:5000']]);
        $this->shipments->contactUser($order, $data['logistics_note'], $request->user());

        return back()->with('success', '已记录联系用户');
    }

    public function cancelException(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['logistics_note' => ['required', 'string', 'max:5000']]);
        $this->shipments->cancelException($order, $data['logistics_note'], $request->user());

        return back()->with('success', '订单已取消，未执行实际退款');
    }

    public function markCompleted(Request $request, Order $order): RedirectResponse
    {
        $this->shipments->markCompleted($order, $request->user());

        return back()->with('success', '物流已标记完结');
    }

    public function updateTracking(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['tracking_number' => ['required', 'string', 'max:128']]);
        $updated = $this->shipments->updateTracking($order, $data['tracking_number'], $request->user());
        $sync = $this->handoffAfterShipment($updated);

        return back()->with('success', ($sync['status'] ?? null) === 'handoff_failed'
            ? '追踪号已更新，但官网待同步任务登记失败，请管理员核对。'
            : (($sync['status'] ?? null) === 'pending'
                ? '追踪号已更新，官网由同步模块每 30 分钟处理。'
                : (($sync['status'] ?? null) === 'completed'
                    ? '追踪号已更新，官网物流写回已验证'
                    : '追踪号已更新，官网同步状态请在发货列表核对')));
    }

    public function markException(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['logistics_note' => ['required', 'string', 'max:5000']]);
        $this->shipments->markException($order, $data['logistics_note'], $request->user());

        return back()->with('success', '已标记物流异常');
    }

    public function resolveException(Request $request, Order $order): RedirectResponse
    {
        $this->shipments->resolveException($order, $request->user());

        return back()->with('success', '异常已解除，物流已恢复运输中');
    }

    public function markShipped(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'carrier' => ['required', 'string', 'max:128', Rule::exists('logistics_carriers', 'name')->where('is_active', true)],
            'service' => ['required', 'string', 'max:128'],
            'shipped_at' => ['required', 'date'],
            'tracking_number' => ['nullable', 'string', 'max:128'],
            'logistics_note' => ['nullable', 'string', 'max:5000'],
        ]);
        $shipped = $this->assistedFulfillment->markShipped($order, $data, $request->user());
        $sync = $this->handoffAfterShipment($shipped);
        $msg = empty($data['tracking_number']) ? '已标记发货，追踪号待填入'
            : (($sync['status'] ?? null) === 'handoff_failed'
                ? 'ERP 已发货，但官网待同步任务登记失败，请管理员核对。'
                : (($sync['status'] ?? null) === 'pending'
                    ? 'ERP 已发货，官网由同步模块每 30 分钟处理。'
                    : (($sync['status'] ?? null) === 'completed'
                        ? '已标记发货，官网物流写回已验证'
                        : '已标记发货，官网物流待同步或待核对')));

        return back()->with('success', $msg);
    }

    /** All ERP shipment/tracking writes only stage an intent for Sync OS. */
    private function handoffAfterShipment(Order $order): array
    {
        return app(ShipmentWebsiteSyncHandoff::class)->afterShipmentSaved($order);
    }

    public function reopen(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['logistics_note' => ['nullable', 'string', 'max:5000']]);
        $this->shipments->reopen($order, $request->user(), $data['logistics_note'] ?? null);

        return back()->with('success', '发货已重开，可修正后重新发货');
    }

    public function reallocatePendingFulfillment(Request $request, Order $order): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'note' => ['nullable', 'string', 'max:1000'],
            'stock_exception_acknowledged' => ['sometimes', 'boolean'],
        ]);
        $updated = $this->submitAllocationForInertia($request, fn () => $this->pendingReallocation->reallocate(
            $order,
            (int) $data['warehouse_id'],
            $request->user(),
            $data['note'] ?? null,
            (bool) ($data['stock_exception_acknowledged'] ?? false),
        ));

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'reallocated',
                'order_id' => (int) $updated->getKey(),
                'suffix' => $updated->fulfillment_suffix,
                'warehouse_id' => $updated->shipment?->warehouse_id,
                'warehouse_name' => $updated->shipment?->warehouse?->name,
            ]);
        }

        return back()->with('success', '履约 '.$updated->fulfillment_suffix.' 已调整分仓');
    }

    public function createReplacementFulfillment(Request $request, Order $order): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', Rule::in(array_keys(ReplacementFulfillmentService::REASONS))],
            'origin_fulfillment_id' => ['nullable', 'integer'],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'note' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'address' => ['nullable', 'array'],
            'address.recipient_name' => ['nullable', 'string', 'max:255'],
            'address.phone' => ['nullable', 'string', 'max:64'],
            'address.country' => ['nullable', 'string', 'max:128'],
            'address.province_state' => ['nullable', 'string', 'max:255'],
            'address.city' => ['nullable', 'string', 'max:255'],
            'address.address_line_1' => ['nullable', 'string', 'max:500'],
            'address.address_line_2' => ['nullable', 'string', 'max:500'],
            'address.postal_code' => ['nullable', 'string', 'max:64'],
        ]);
        $replacement = $this->replacementFulfillments->create(
            $order,
            $data['reason'],
            $data['items'],
            (int) $data['warehouse_id'],
            isset($data['origin_fulfillment_id']) ? (int) $data['origin_fulfillment_id'] : null,
            $data['note'] ?? null,
            $data['address'] ?? null,
            $request->user(),
        );
        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'created',
                'order_id' => (int) $replacement->getKey(),
                'erp_order_id' => $replacement->erp_order_id,
                'suffix' => $replacement->fulfillment_suffix,
            ], 201);
        }

        return back()->with('success', '补发履约单 '.$replacement->fulfillment_suffix.' 已创建');
    }

    public function payload(Order $order): JsonResponse
    {
        $order->load(['address', 'items', 'shipment.warehouse']);
        $packaging = $this->packagingReferences->forOrder($order);

        return response()->json([
            'order_number' => $order->erp_order_id,
            'source_order_number' => $order->external_order_number,
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
            'items' => $order?->items->map(fn ($item): array => ['sku' => WarehouseSkuPresentation::sku($item), 'source_sku' => $item->source_sku ?: $item->sku, 'product_summary' => $item->product_summary, 'product_id' => $item->product_id, 'product_url' => $item->product ? "/products/{$item->product_id}" : null, 'quantity' => $item->quantity])->values()->all() ?? [],
            'review_pending' => $order?->logisticsReview?->status === 'pending',
            'paused' => $order?->operationalMarkers?->contains('marker', 'logistics_paused') ?? false,
        ];
    }

    private function projection(Request $request, string $kind, string $component, \Closure $filter): Response
    {
        $shipments = Shipment::query()->with(['order.address', 'order.items.product', 'order.logisticsReview', 'warehouse'])->where($filter)->whereDoesntHave('order.logisticsReview', fn ($q) => $q->where('status', 'pending'))->whereHas('order', fn ($q) => $q->eligibleForOperations()->where('order_status', '!=', 'cancelled')->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')))
            ->latest('shipped_at')->latest('id')->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render($component, ['shipments' => $shipments->through(fn ($s) => $this->queueRow($s))->toArray(), 'statusBar' => $this->statusSummary->statusBar()]);
    }

    private function orderProjection(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_id' => $order->id,
            'order_number' => $order->erp_order_id,
            'erp_order_id' => $order->erp_order_id,
            'external_order_number' => $order->external_order_number,
            'order_date' => $order->order_date?->toDateString(),
            'recipient_name' => $order->address?->recipient_name,
            'country' => $order->address?->country,
            'order_url' => $this->orderUrl($order),
            'items' => $order->items->map(fn ($item) => [
                'sku' => WarehouseSkuPresentation::sku($item),
                'source_sku' => $item->source_sku ?: $item->sku,
                'product_summary' => $item->product_summary,
                'product_id' => $item->product_id,
                'product_url' => $item->product ? "/products/{$item->product_id}" : null,
                'quantity' => (int) $item->quantity,
            ])->all(),
        ];
    }

    private function orderUrl(?Order $order): ?string
    {
        if (! $order || $order->order_kind?->value === 'fulfillment_derived') {
            return null;
        }

        return $order->order_kind?->value === 'kol_fulfillment' ? "/kol/orders/{$order->id}" : "/customers/orders/{$order->id}";
    }

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

    private function data(
        Order $order,
        array $context,
        array $activeReservationsByOrderId = [],
        array $paymentDecisionsByOrderId = [],
    ): array {
        $destinationContext = [
            'province_state' => $order->address?->province_state,
            'city' => $order->address?->city,
            'address_line_1' => $order->address?->address_line_1,
            'address_line_2' => $order->address?->address_line_2,
            'postal_code' => $order->address?->postal_code,
        ];
        $planItems = $order->items->map(fn ($item): array => [
            'product_id' => $item->product_id,
            'quantity' => (int) $item->quantity,
            'sku' => $item->sku,
        ])->values()->all();
        $recommendation = $this->recommendationFor($order, $context, $destinationContext, $planItems);
        $fulfillmentPlanPreview = $this->fulfillmentPlans->preview(
            $order->address?->country,
            $planItems,
            $destinationContext,
            [],
            $context,
        );

        $allocationDecision = $this->allocationDecisions->present($order, $fulfillmentPlanPreview);
        $allocation = $order->relationLoaded('fulfillmentAllocation') ? $order->fulfillmentAllocation : null;
        $usZone = strtoupper((string) $order->address?->country) === 'US'
            ? $this->usZones->resolve($order->address?->postal_code, $destinationContext)
            : null;
        $carrierRoutes = collect((array) config('warehouse_master.fulfillment_codes', []))->mapWithKeys(function (string $code) use ($order): array {
            $route = strtoupper(trim($code)) === 'FACTORY'
                ? [] : $this->carrierRouting->warehouseRoute($order->address?->country, $code);
            $carrier = trim((string) ($route['carrier'] ?? ''));

            return [$code => [
                'carrier' => $carrier !== '' ? $carrier : null,
                'carrier_label' => $carrier !== '' ? $this->carrierRouting->displayName($carrier) : null,
                'service' => isset($route['service']) && trim((string) $route['service']) !== '' ? trim((string) $route['service']) : null,
                'automatic' => $carrier !== '',
            ]];
        })->all();
        $amount = app(DomesticReviewAmountResolver::class)->resolve($order);

        return [
            'id' => $order->id,
            'external_order_number' => $order->external_order_number,
            'order_date' => $order->order_date?->toDateString(),
            'amount' => $amount['amount'],
            'amount_currency' => $amount['currency'],
            'amount_source' => $amount['source'],
            'source_type' => $order->source_type?->value,
            'order_status' => $order->order_status,
            'order_url' => $this->orderUrl($order),
            'domestic_reject_reason' => $order->domestic_reject_reason,
            'allocation_rejection' => $this->rejectionReadModel->forOrder($order),
            'erp_order_id' => $order->erp_order_id,
            'internal_order_number' => $order->internal_order_number,
            'order_kind' => $order->order_kind?->value,
            'order_kind_label' => match ($order->order_kind) {
                OrderKind::Customer => '客户订单',
                OrderKind::KolFulfillment => '网红履约订单',
                OrderKind::FulfillmentDerived => '履约衍生订单',
                null => '未分类',
            },
            'recipient_name' => $order->address?->recipient_name,
            'phone' => $order->address?->phone,
            'country' => $order->address?->country,
            'province_state' => $order->address?->province_state,
            'city' => $order->address?->city,
            'address_line_1' => $order->address?->address_line_1,
            'address_line_2' => $order->address?->address_line_2,
            'postal_code' => $order->address?->postal_code,
            'us_fulfillment_zone' => $usZone,
            'carrier_routes' => $carrierRoutes,
            'owner_label' => $order->owner ? 'Owner('.$order->owner->name.')' : null,
            'item_count' => $order->items->sum('quantity'),
            'items' => $order->items->map(fn ($item): array => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_url' => $item->product ? "/products/{$item->product_id}" : null,
                'sku' => $item->sku,
                'source_sku' => $item->source_sku ?: $item->sku,
                'resolved_fulfillment_sku' => $item->resolved_fulfillment_sku,
                'product_summary' => $item->product_summary,
                'quantity' => $item->quantity,
                'availability' => $context['availability'][$item->product_id] ?? [],
                'is_gift' => $this->giftSkuResolutions->isGiftSku($item->source_sku ?: $item->sku),
                'gift_options_by_warehouse' => array_key_exists('gift_resolution', $context)
                    ? $this->giftSkuResolutions->options((string) ($item->source_sku ?: $item->sku), (int) $item->quantity, $context['gift_resolution'])
                    : [],
            ])->values()->all(),
            'warehouse_recommendation' => $recommendation,
            'fulfillment_plan_preview' => $fulfillmentPlanPreview,
            'allocation_decision' => $allocationDecision,
            'fulfillment_allocation' => $allocation ? [
                'status' => $allocation->status,
                'decision_source' => $allocation->decision_source,
                'selected_plan_key' => $allocation->selected_plan_key,
                'decision_note' => $allocation->decision_note,
                'plan_snapshot' => $allocation->plan_snapshot,
                'submitted_at' => $allocation->submitted_at?->toIso8601String(),
            ] : null,
            'automatic_allocation' => $this->automaticAllocationEligibility->evaluate(
                $order,
                $recommendation,
                $activeReservationsByOrderId,
                $paymentDecisionsByOrderId[$order->id] ?? null,
            ),
            'shipment' => $order->shipment ? [
                ...$order->shipment->only(['warehouse_id', 'warehouse_selection_source', 'carrier', 'service', 'carrier_selection_source', 'tracking_number', 'shipped_at', 'logistics_note']),
                'warehouse_name' => $order->shipment->warehouse?->name,
            ] : null,
        ];
    }

    /** @return array<string,mixed> */
    private function recommendationFor(Order $order, array $context, array $destinationContext, array $items): array
    {
        try {
            return $this->recommendations->recommend(
                $order->address?->country,
                $items,
                $order->address?->postal_code,
                [],
                $destinationContext,
                $context,
            );
        } catch (QueryException|PDOException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('erp.logistics.recommendation_row_failed', [
                'order_id' => $order->id,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            return [
                'status' => 'blocked',
                'reason_code' => 'recommendation_failed',
                'reason_label' => '该订单推荐计算暂时失败，请人工处理。',
                'country_code' => $order->address?->country,
                'required_sku_count' => null,
                'candidate_count' => 0,
                'warehouse' => null,
                'routing_context' => null,
            ];
        }
    }
}
