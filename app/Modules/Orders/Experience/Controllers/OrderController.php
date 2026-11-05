<?php

namespace App\Modules\Orders\Experience\Controllers;

use App\Modules\Audit\Infrastructure\Models\AuditRecord;
use App\Modules\Finance\Application\OrderAnalysisService;
use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use App\Modules\Kol\Application\KolAffiliateIdentity;
use App\Modules\Kol\Application\KolScope;
use App\Modules\Kol\Application\SavedViewService;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolCollaboration;
use App\Modules\Logistics\Application\CarrierRoutingPolicy;
use App\Modules\Logistics\Application\CarrierServiceCatalog;
use App\Modules\Logistics\Application\FulfillmentAllocationAccess;
use App\Modules\Logistics\Application\FulfillmentAllocationDecisionService;
use App\Modules\Logistics\Application\FulfillmentPlanPreviewService;
use App\Modules\Logistics\Application\LogisticsExecutionAccess;
use App\Modules\Logistics\Application\OverseaWarehouseReviewService;
use App\Modules\Logistics\Application\PendingAllocationEntryPolicy;
use App\Modules\Logistics\Application\ShipmentCompletionSemantics;
use App\Modules\Orders\Application\Inbound\WooOrderManualSyncAccess;
use App\Modules\Orders\Application\KolOrderAccessScope;
use App\Modules\Orders\Application\OrderItemAmendmentPreflight;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderPackagingReferenceProjection;
use App\Modules\Orders\Application\OrderService;
use App\Modules\Orders\Application\OrderSourceType;
use App\Modules\Orders\Application\OrderWorkflowStatus;
use App\Modules\Orders\Application\OrderWorkflowStatusProjection;
use App\Modules\Orders\Application\OrderWorkflowStatusService;
use App\Modules\Orders\Application\WooCommerceAdminOrderLink;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\WooOrderSyncHealthReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class OrderController
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly OrderItemAmendmentPreflight $itemAmendmentPreflight,
        private readonly InventoryAvailabilityReader $availability,
        private readonly SavedViewService $views,
        private readonly OrderPackagingReferenceProjection $packagingReferences,
        private readonly OrderAnalysisService $orderAnalysis,
        private readonly FulfillmentPlanPreviewService $fulfillmentPlans,
        private readonly FulfillmentAllocationDecisionService $allocationDecisions,
        private readonly FulfillmentAllocationAccess $fulfillmentAllocationAccess,
        private readonly LogisticsExecutionAccess $logisticsExecutionAccess,
        private readonly ShipmentCompletionSemantics $shipmentCompletion,
        private readonly CarrierServiceCatalog $carrierServices,
        private readonly CarrierRoutingPolicy $carrierRouting,
        private readonly WooCommerceAdminOrderLink $wooAdminLink,
        private readonly OrderWorkflowStatusService $workflowStatuses,
        private readonly OrderWorkflowStatusProjection $workflowProjectionService,
    ) {}

    public function index(Request $request): Response
    {
        $kind = $this->kindOrNull($request);
        $filters = $request->only(['search', 'source_type', 'order_status', 'workflow_status', 'view']);
        $scope = (string) $request->route('order_scope');
        if ($scope === 'external_warehouse') {
            $filters['warehouse_ownership'] = 'external';
        }
        if ($kind) {
            $filters['order_kind'] = $kind->value;
        }
        $filters = KolOrderAccessScope::scopeFilters($request->user(), $filters);
        // R3 opt-in: real Orders pilot is limited to non-Production and never changes legacy pages by default.
        $omsGridAvailable = $kind === null && $scope !== 'external_warehouse'
            && app()->environment(['local', 'development', 'staging', 'testing']);
        $omsGridEnabled = $omsGridAvailable && $request->boolean('oms_grid');
        $requestedPageSize = $omsGridEnabled ? (string) $request->query('per_page', '50') : '50';
        $perPage = in_array($requestedPageSize, ['20', '50', '100'], true) ? (int) $requestedPageSize : 50;
        $sort = $omsGridEnabled && in_array($request->query('sort'), ['order_number', 'date', 'source', 'status'], true)
            ? (string) $request->query('sort') : 'date';
        $direction = $omsGridEnabled && $request->query('direction') === 'asc' ? 'asc' : 'desc';
        $gridColumnFilters = [];
        if ($omsGridEnabled) {
            $filters['oms_sort'] = $sort;
            $filters['oms_direction'] = $direction;
            $filters['oms_numeric_contains'] = true;
            foreach (['grid_order_number', 'grid_sku', 'grid_quantity', 'grid_product_names', 'grid_source', 'grid_date', 'grid_recipient', 'grid_country', 'grid_warehouse', 'grid_status', 'grid_tracking', 'grid_owner', 'grid_city', 'grid_postal_code', 'grid_carrier', 'grid_external_number'] as $key) {
                $raw = $request->query($key);
                if (is_string($raw)) {
                    $value = mb_substr(trim($raw), 0, 80);
                    if ($value !== '') $gridColumnFilters[$key] = $value;
                }
            }
            $filters['oms_grid_columns'] = $gridColumnFilters;
        }
        $orders = $this->orders->list($filters, $requestedPageSize === 'all' && $omsGridEnabled ? 200 : $perPage);
        $allRowsAllowed = $omsGridEnabled && $requestedPageSize === 'all' && $orders->total() <= 200;
        if ($requestedPageSize === 'all' && ! $allRowsAllowed) {
            $orders = $this->orders->list($filters, 50);
        }

        $isCustomerKind = $kind?->value === 'customer';
        $canExport = $isCustomerKind
            ? ($request->user()->can('customers.export') && $request->user()->can('system.permissions.manage'))
            : $request->user()->can('orders.view');

        return Inertia::render('Orders/Index', [
            'orderKind' => $kind?->value ?? 'all',
            'orderScope' => $scope === 'external_warehouse' ? 'external_warehouse' : 'all',
            'orders' => $orders->through(fn (Order $order): array => $this->orderData($order))->toArray(),
            'filters' => [
                ...$request->only(['search', 'source_type', 'order_status', 'workflow_status', 'view']),
                ...($omsGridEnabled ? ['sort' => $sort, 'direction' => $direction, 'per_page' => $allRowsAllowed ? 'all' : (string) $orders->perPage(), 'oms_grid' => '1', ...$gridColumnFilters] : []),
            ],
            'omsGridAvailable' => $omsGridAvailable,
            'omsGridEnabled' => $omsGridEnabled,
            'canImportOmsOrders' => $omsGridEnabled && $request->user()->can('orders.create') && ! KolOrderAccessScope::isKolOnly($request->user()),
            'workflowStatusOptions' => collect(OrderWorkflowStatus::registry())->map(fn (array $definition, string $code): array => ['code' => $code, ...$definition])->values()->all(),
            'savedViews' => $this->views->forUser('orders', (int) $request->user()->getAuthIdentifier(), false),
            'sourceTypes' => array_map(fn (OrderSourceType $source): array => ['value' => $source->value, 'label' => $this->sourceLabel($source)], OrderSourceType::cases()),
            'canExport' => $canExport,
            'canEditWorkflowStatus' => $request->user()->can('orders.edit'),
            'canPreviewKolLinks' => $kind === OrderKind::KolFulfillment && $request->user()->can('kol.views.manage'),
            'canManageTrash' => $request->user()?->can('system.permissions.manage') ?? false,
            'wooOrderSync' => $request->user()->can('sync.view') ? [
                ...app(WooOrderSyncHealthReader::class)->snapshot(),
                'can_import_today' => app(WooOrderManualSyncAccess::class)->allows($request->user()),
            ] : [
                'health' => 'disabled',
                'last_success_at' => null,
                'last_failure' => null,
                'can_import_today' => false,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $kind = $this->kind($request);
        KolOrderAccessScope::authorizeCreate($request->user(), $kind);

        $kolOptions = $kind === OrderKind::KolFulfillment && $request->user()->can('kol.view')
            ? Kol::query()->whereNull('archived_at')
                ->when(! KolScope::isAllScope($request->user()),
                    fn ($query) => $query->where('owner_id', $request->user()->getAuthIdentifier()))
                ->withExists(['affiliateAccounts as affiliate_ready' => fn ($query) => KolAffiliateIdentity::scopeActiveAffiliate($query)])
                ->orderBy('display_name')->limit(1500)->get(['id', 'display_name'])
                ->map(fn (Kol $kol): array => [
                    'value' => (int) $kol->id, 'label' => $kol->display_name,
                    'hasAffiliate' => (bool) $kol->affiliate_ready,
                ])->all()
            : [];
        $requestedKolId = $request->integer('kol_id');
        $requestedKol = collect($kolOptions)->firstWhere('value', $requestedKolId);
        $selectedKolId = $requestedKol && $requestedKol['hasAffiliate'] ? $requestedKolId : null;
        $requestedKolUnbound = $requestedKol && ! $requestedKol['hasAffiliate'];

        return Inertia::render('Orders/Create', [
            'defaultOrderKind' => $kind->value,
            'orderKindLabel' => $this->kindLabel($kind),
            'generatedExternalOrderNumber' => $this->orders->generateExternalOrderNumber($kind),
            'kolOptions' => $kolOptions,
            'selectedKolId' => $selectedKolId,
            'requestedKolUnbound' => (bool) $requestedKolUnbound,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        KolOrderAccessScope::authorizeCreate($request->user(), $this->kind($request));
        $kind = $this->kind($request);
        // A new manually created KOL order must never use the legacy identity-less path.
        if ($kind === OrderKind::KolFulfillment) {
            abort_unless($request->user()->can('kol.view'), 403);
        }
        $data = $request->validate([
            ...$this->rules(),
            'kol_id' => $kind === OrderKind::KolFulfillment
                ? ['required', 'integer', Rule::exists('kols', 'id')->whereNull('archived_at')]
                : ['prohibited'],
        ]);
        if ($kind === OrderKind::KolFulfillment && filled($data['kol_id'] ?? null)) {
            abort_unless($request->user()->can('kol.view'), 403);
            $kol = Kol::query()->findOrFail($data['kol_id']);
            abort_unless(KolScope::isAllScope($request->user()) || (int) $kol->owner_id === (int) $request->user()->getAuthIdentifier(), 403);
            if (! KolAffiliateIdentity::isReady((int) $kol->id)) {
                throw ValidationException::withMessages([
                    'kol_id' => KolAffiliateIdentity::MISSING_MESSAGE,
                ]);
            }
        } else {
            unset($data['kol_id']);
        }
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

    public function showAny(Request $request, Order $order): RedirectResponse
    {
        $target = $order->order_kind === OrderKind::FulfillmentDerived
            ? $order->fulfillmentParent()->firstOrFail() : $order;
        abort_if(($order->archived_at !== null || $target->archived_at !== null)
            && ! $request->user()?->can('system.permissions.manage'), 404);
        KolOrderAccessScope::authorize($request->user(), $target);

        // Keep the derived identity: routing to its source root loses the
        // segment-specific warehouse, shipment, items and status details.
        return match ($target->order_kind) {
            OrderKind::Customer => redirect()->route('customers.orders.show', $order),
            OrderKind::KolFulfillment => redirect()->route('kol.orders.show', $order),
            default => abort(404),
        };
    }

    public function show(Request $request, Order $order): Response
    {
        $authority = $this->detailAuthority($request, $order);
        KolOrderAccessScope::authorize($request->user(), $authority);
        $isDerived = $order->order_kind === OrderKind::FulfillmentDerived;
        $order->load(['items', 'owner', 'address', 'shipment.warehouse', 'manualSync', 'operationalMarkers', 'procurementOrder', 'fulfillmentAllocation', 'fulfillmentParent', 'fulfillmentChildren.items', 'fulfillmentChildren.shipment.warehouse', 'fulfillmentChildren.fulfillmentOrigin']);
        $productIds = $order->items->pluck('product_id')->filter()->unique()->values()->all();
        $inventoryAvailability = $this->availability->forProductIds($productIds);
        $warehouses = $this->availability->activeWarehouses();
        $allocationUi = $isDerived ? null : $this->allocationUi($request, $order, $productIds, $warehouses, $inventoryAvailability);

        return Inertia::render('Orders/Show', [
            'order' => $this->orderData($order),
            'orderNavigation' => $this->adjacentOrderNavigation($request, $order),
            'wooAdminEditUrl' => $isDerived ? null : $this->wooAdminLink->forOrder($order),
            'inventoryAvailability' => $inventoryAvailability,
            'allocationUi' => $allocationUi,
            'packagingReferences' => $this->packagingReferences->forOrder($order),
            'warehouses' => $warehouses,
            'warehouseRouting' => collect($warehouses)->mapWithKeys(function (array $warehouse) use ($order): array {
                $route = $this->carrierRouting->warehouseRoute($order->address?->country, (string) ($warehouse['code'] ?? ''));

                return [(int) $warehouse['id'] => [
                    'carrier' => $route['carrier'] ?? null,
                    'service' => $route['service'] ?? null,
                    'carrier_service_id' => $route['carrier_service_id'] ?? null,
                    'automatic' => filled($route['carrier'] ?? null),
                ]];
            })->all(),
            'carrierOptions' => $this->carrierServices->options(),
            'canViewProfit' => ! $isDerived && ($request->user()?->can('analysis.view') ?? false),
            'kolProfile' => $this->kolProfileForOrder($request, $order),
            'canMarkOperational' => ! $isDerived && ! $order->isOperationallyArchived() && ($request->user()?->can('orders.edit') ?? false),
            'workflowStatusOptions' => collect(OrderWorkflowStatus::registry())->map(fn (array $definition, string $code): array => ['code' => $code, ...$definition])->values()->all(),
            'canEditOrder' => ! $isDerived && ! $order->isOperationallyArchived() && ($request->user()?->can('orders.edit') ?? false),
            'canTrashOrder' => ! $isDerived && ! $order->isOperationallyArchived() && ($request->user()?->can('orders.edit') ?? false),
            'canArchiveOrder' => ! $order->trashed() && $order->archived_at === null && $order->operational_archived_at === null && ($request->user()?->can('orders.edit') ?? false),
            'directlyArchived' => $order->operational_archived_at !== null,
            'operationallyArchived' => $order->isOperationallyArchived(),
            'canManageTrash' => $request->user()?->can('system.permissions.manage') ?? false,
            'canCreateReplacement' => ! $isDerived && $this->logisticsExecutionAccess->allows($request->user()) && $this->originalLogisticsStarted($order),
            'canCreateProcurement' => ! $isDerived && ($request->user()?->can('procurement.create') ?? false) && $order->operationalMarkers->contains('marker', 'stockout'),
            'canManageProductPackaging' => ! $isDerived && ($request->user()?->can('products.operate') ?? false),
            'canOperateLogistics' => $this->logisticsExecutionAccess->allows($request->user()),
            'canReopenFulfillment' => $this->fulfillmentAllocationAccess->allows($request->user()),
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

    /**
     * Navigate in the same order as the default order list: order_date DESC, id DESC.
     * Only discover orders of the same kind within the viewer's permitted scope.
     *
     * @return array{previous_url:?string,next_url:?string}
     */
    private function adjacentOrderNavigation(Request $request, Order $order): array
    {
        // Derived segments can have different source owners (customer / KOL).
        // Cross-order navigation must not expose unrelated segments.
        if ($order->order_kind === OrderKind::FulfillmentDerived) {
            return ['previous_url' => null, 'next_url' => null];
        }

        $query = Order::query()
            ->where('order_kind', $order->order_kind->value)
            ->whereNull('archived_at');

        if (KolOrderAccessScope::isOwnerOnly($request->user())) {
            $query->where('owner_id', (int) $request->user()->getAuthIdentifier());
        }

        $date = $order->order_date->toDateString();
        $id = (int) $order->id;

        $previous = (clone $query)
            ->where(fn ($query) => $query->whereDate('order_date', '>', $date)
                ->orWhere(fn ($sameDate) => $sameDate->whereDate('order_date', $date)->where('id', '>', $id)))
            ->orderBy('order_date')->orderBy('id')->value('id');

        $next = (clone $query)
            ->where(fn ($query) => $query->whereDate('order_date', '<', $date)
                ->orWhere(fn ($sameDate) => $sameDate->whereDate('order_date', $date)->where('id', '<', $id)))
            ->orderByDesc('order_date')->orderByDesc('id')->value('id');

        $routeName = $order->order_kind === OrderKind::KolFulfillment
            ? 'kol.orders.show' : 'customers.orders.show';

        return [
            'previous_url' => $previous ? route($routeName, $previous, false) : null,
            'next_url' => $next ? route($routeName, $next, false) : null,
        ];
    }

    /** @return array{id:int,display_name:string,url:string}|null */
    private function kolProfileForOrder(Request $request, Order $order): ?array
    {
        if ($order->order_kind !== OrderKind::KolFulfillment || ! $request->user()?->can('kol.view')) {
            return null;
        }

        $kolId = $order->kol_id;
        if (! $kolId) {
            // Historical fallback is authoritative only when precisely one formal collaboration exists.
            $ids = KolCollaboration::query()->where('order_id', $order->id)->distinct()
                ->limit(2)->pluck('kol_id');
            if ($ids->count() !== 1) {
                return null;
            }
            $kolId = (int) $ids->first();
        }

        $kol = Kol::query()->find($kolId);
        if (! $kol || (! KolScope::isAllScope($request->user())
            && (int) $kol->owner_id !== (int) $request->user()->getAuthIdentifier())) {
            return null;
        }

        return ['id' => (int) $kol->id, 'display_name' => $kol->display_name,
            'url' => route('kol.library.show', $kol, false)];
    }

    /** @param array<int,int> $productIds @param array<int,array<string,mixed>> $warehouses @param array<int,array<int,array<string,mixed>>> $availability */
    private function allocationUi(Request $request, Order $order, array $productIds, array $warehouses, array $availability): array
    {
        $allowedCodes = array_map('strtoupper', (array) config('warehouse_master.fulfillment_codes', []));
        $selectableWarehouseIds = collect($warehouses)
            ->filter(fn (array $warehouse): bool => in_array(strtoupper((string) ($warehouse['code'] ?? '')), $allowedCodes, true))
            ->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();

        $recommendedByItem = collect($order->items)->mapWithKeys(fn ($item): array => [(int) $item->id => null])->all();
        $recommendedPlanKey = null;
        $canUseRecommendation = false;

        if ($order->order_status?->value === 'pending_shipment' && $productIds !== []) {
            try {
                $context = array_replace(
                    ['warehouses' => $warehouses, 'availability' => $availability],
                    $this->fulfillmentPlans->prepare($productIds),
                );
                $destination = [
                    'province_state' => $order->address?->province_state,
                    'city' => $order->address?->city,
                    'address_line_1' => $order->address?->address_line_1,
                    'address_line_2' => $order->address?->address_line_2,
                    'postal_code' => $order->address?->postal_code,
                ];
                $items = $order->items->map(fn ($item): array => [
                    'product_id' => $item->product_id,
                    'quantity' => (int) $item->quantity,
                    'sku' => $item->sku,
                ])->values()->all();
                $preview = $this->fulfillmentPlans->preview($order->address?->country, $items, $destination, [], $context);
                $decision = $this->allocationDecisions->present($order, $preview);
                $recommendedPlanKey = $decision['recommended_plan_key'] ?? null;
                $canUseRecommendation = (bool) ($decision['can_use_recommendation'] ?? false);
                foreach (($decision['recommended_plan']['segments'] ?? []) as $segment) {
                    foreach (($segment['items'] ?? []) as $planItem) {
                        $matched = $order->items->first(function ($item) use ($planItem): bool {
                            if ($item->product_id !== null && isset($planItem['product_id'])) {
                                return (int) $item->product_id === (int) $planItem['product_id'];
                            }

                            return trim((string) $item->sku) !== '' && trim((string) $item->sku) === trim((string) ($planItem['sku'] ?? ''));
                        });
                        if ($matched && isset($segment['warehouse_id'])) {
                            $recommendedByItem[(int) $matched->id] = (int) $segment['warehouse_id'];
                        }
                    }
                }
            } catch (\Throwable) {
                // Inventory remains visible even when recommendation evidence is incomplete.
            }
        }

        $selectedByItem = collect($order->items)->mapWithKeys(fn ($item): array => [(int) $item->id => null])->all();
        $snapshot = $order->fulfillmentAllocation?->plan_snapshot;
        foreach ((array) ($snapshot['segments'] ?? []) as $segment) {
            foreach ((array) ($segment['items'] ?? []) as $planItem) {
                $matched = $order->items->first(function ($item) use ($planItem): bool {
                    if ($item->product_id !== null && isset($planItem['product_id'])) {
                        return (int) $item->product_id === (int) $planItem['product_id'];
                    }

                    return trim((string) $item->sku) !== '' && trim((string) $item->sku) === trim((string) ($planItem['sku'] ?? ''));
                });
                if ($matched && isset($segment['warehouse_id'])) {
                    $selectedByItem[(int) $matched->id] = (int) $segment['warehouse_id'];
                }
            }
        }
        if (! $order->fulfillmentAllocation && $order->shipment?->warehouse_id) {
            foreach ($selectedByItem as $itemId => $_) {
                $selectedByItem[$itemId] = (int) $order->shipment->warehouse_id;
            }
        }

        $originalChildren = $order->relationLoaded('fulfillmentChildren')
            ? $order->fulfillmentChildren->filter(fn (Order $child): bool => (string) ($child->fulfillment_role ?? '') !== 'replacement')->values()
            : collect();
        $hasCommittedDerivedFulfillment = $originalChildren->isNotEmpty();
        $allocationCommitted = $order->fulfillmentAllocation !== null || $hasCommittedDerivedFulfillment;
        $fulfillmentSegments = $originalChildren->isNotEmpty() ? $originalChildren : collect([$order]);
        $logisticsStarted = $fulfillmentSegments->contains(function (Order $segment): bool {
            $shipment = $segment->shipment;
            if (! $shipment) {
                return false;
            }

            return $shipment->shipment_status !== 'pending' || filled($shipment->tracking_number) || $shipment->shipped_at !== null;
        });
        $requiresFullFlow = $order->items->contains(fn ($item): bool => strcasecmp(trim((string) $item->sku), 'gift') === 0);
        $allocationAllowed = $this->fulfillmentAllocationAccess->allows($request->user());
        $entryAllowed = app(PendingAllocationEntryPolicy::class)->allows($order);
        $canReallocate = $order->order_status?->value === 'pending_shipment'
            && $allocationCommitted
            && ! $logisticsStarted
            && $allocationAllowed
            && ! $requiresFullFlow;
        $canPartialReallocate = $allocationCommitted
            && $originalChildren->count() > 1
            && $logisticsStarted
            && $allocationAllowed;

        return [
            'status' => $allocationCommitted ? 'submitted' : ($order->order_status?->value === 'pending_shipment' ? 'pending' : 'locked'),
            'can_operate' => $entryAllowed && $order->order_status?->value === 'pending_shipment' && ! $allocationCommitted && $allocationAllowed,
            'can_reallocate' => $canReallocate,
            'can_partial_reallocate' => $canPartialReallocate,
            'logistics_started' => $logisticsStarted,
            'requires_full_flow' => $requiresFullFlow,
            'selectable_warehouse_ids' => $selectableWarehouseIds,
            'recommended_warehouse_ids' => $recommendedByItem,
            'selected_warehouse_ids' => $selectedByItem,
            'recommended_plan_key' => $recommendedPlanKey,
            'can_use_recommendation' => $canUseRecommendation,
            'full_allocation_url' => route('logistics.wait-stock'),
        ];
    }

    public function previewItemAmendment(Request $request, Order $order): JsonResponse
    {
        $this->authorizeKind($request, $order);
        KolOrderAccessScope::authorize($request->user(), $order);
        abort_if($order->isOperationallyArchived(), 409, '订单已归档，不能编辑商品。');
        $data = $request->validate($this->itemAmendmentRules());

        return response()->json($this->itemAmendmentPreflight->preview($order, $data['items']));
    }

    public function update(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeKind($request, $order);
        KolOrderAccessScope::authorize($request->user(), $order);
        abort_if($order->isOperationallyArchived(), 409, '运营归档订单为只读，不能编辑。');
        $data = $request->validate($this->updateRules($order));
        $items = $data['items'] ?? null;
        $reason = trim((string) ($data['amendment_reason'] ?? ''));
        unset($data['items'], $data['amendment_reason'], $data['expected_revision']);

        // Lock the source order before rechecking inventory/fulfillment gates. The
        // preview itself is read-only; a concurrent allocation must never race a save.
        DB::transaction(function () use ($request, $order, $data, $items, $reason): void {
            $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            abort_if($lockedOrder->isOperationallyArchived(), 409, '运营归档订单为只读，不能编辑。');
            if ($items !== null) {
                $preview = $this->itemAmendmentPreflight->preview($lockedOrder, $items);
                if (filled($request->input('expected_revision')) && $request->input('expected_revision') !== $preview['expected_revision']) {
                    abort(409, '订单商品已被其他操作修改，请刷新订单并重新预检。');
                }
                if (! $preview['can_save_direct']) {
                    throw ValidationException::withMessages(['items' => $preview['issues'][0] ?? '订单商品不能直接修改。']);
                }
            }
            $this->orders->update($lockedOrder, $data, $items, $request->user(), $reason ?: null);
        });

        return back()->with('success', '订单已更新');
    }

    public function setOperationalMarker(Request $request, Order $order, string $marker): RedirectResponse
    {
        KolOrderAccessScope::authorize($request->user(), $order);
        abort_if($order->isOperationallyArchived(), 409, '运营归档订单为只读，不能修改业务标记。');
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $this->orders->setOperationalMarker($order, $marker, (bool) $data['active'], $request->user());

        return back()->with('success', '订单业务标记已更新');
    }

    public function updateWorkflowStatus(Request $request, Order $order): JsonResponse
    {
        KolOrderAccessScope::authorize($request->user(), $order);
        abort_if($order->isOperationallyArchived(), 409, '运营归档订单为只读，不能修改履约状态。');
        $data = $request->validate([
            'workflow_status' => ['required', 'string', Rule::in(array_keys(array_filter(OrderWorkflowStatus::registry(), fn (array $definition): bool => $definition['mutable'])))],
            'expected_workflow_revision' => ['required', 'integer', 'min:0'],
        ]);
        $updated = $this->workflowStatuses->set($order, $data['workflow_status'], $data['expected_workflow_revision'], $request->user());

        return response()->json(['order' => $this->orderData($updated), 'workflow_status' => $this->workflowProjection($updated)]);
    }

    public function export(Request $request): StreamedResponse
    {
        $kind = $this->kindOrNull($request);
        $filters = $request->only(['search', 'source_type', 'order_status', 'workflow_status', 'view']);
        $scope = (string) $request->route('order_scope');
        if ($scope === 'external_warehouse') {
            $filters['warehouse_ownership'] = 'external';
        }
        if ($kind) {
            $filters['order_kind'] = $kind->value;
        }
        $filters = KolOrderAccessScope::scopeFilters($request->user(), $filters);
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

    /**
     * Resolve the owning business order for a read-only derived detail.
     * This is intentionally separate from authorizeKind: PATCH/edit routes
     * must remain strict and must never mutate generated segment fields.
     */
    private function detailAuthority(Request $request, Order $order): Order
    {
        $authority = $order->order_kind === OrderKind::FulfillmentDerived
            ? $order->fulfillmentParent()->firstOrFail() : $order;
        abort_if($authority->order_kind !== $this->kind($request), HttpResponse::HTTP_NOT_FOUND);
        abort_if(($order->archived_at !== null || $authority->archived_at !== null)
            && ! $request->user()?->can('system.permissions.manage'), HttpResponse::HTTP_NOT_FOUND);

        return $authority;
    }

    private function authorizeKind(Request $request, Order $order): void
    {
        abort_if($order->order_kind !== $this->kind($request), HttpResponse::HTTP_NOT_FOUND);
        abort_if($order->archived_at !== null && ! $request->user()?->can('system.permissions.manage'), HttpResponse::HTTP_NOT_FOUND);
    }

    private function orderData(Order $order): array
    {
        $workflowProjection = $this->workflowProjection($order);

        return [
            'id' => $order->id,
            'internal_order_number' => $order->internal_order_number,
            'erp_order_id' => $order->erp_order_id,
            'external_order_number' => $order->external_order_number,
            'source_type' => $order->source_type->value,
            'source_label' => $this->sourceLabel($order->source_type),
            'order_kind' => $order->order_kind?->value,
            'order_kind_label' => $order->order_kind ? $this->kindLabel($order->order_kind) : null,
            'fulfillment_parent' => $order->fulfillmentParent ? [
                'id' => (int) $order->fulfillmentParent->id,
                'erp_order_id' => $order->fulfillmentParent->erp_order_id,
                'order_kind' => $order->fulfillmentParent->order_kind?->value,
                'url' => route(
                    $order->fulfillmentParent->order_kind === OrderKind::KolFulfillment
                        ? 'kol.orders.show' : 'customers.orders.show',
                    $order->fulfillmentParent,
                    false,
                ),
            ] : null,
            'operationally_archived' => $order->isOperationallyArchived(),
            'order_date' => $order->order_date?->toDateString(),
            'order_status' => $order->order_status->value,
            'order_status_label' => $workflowProjection['code'] === 'allocated' ? '已分仓' : $this->statusLabel($order->order_status->value),
            'workflow_status' => $order->workflow_status,
            'workflow_revision' => (int) ($order->workflow_revision ?? 0),
            'workflow_status_projection' => $workflowProjection,
            'order_note' => $order->order_note,
            'domestic_reject_reason' => $order->domestic_reject_reason,
            'domestic_rejected_at' => $order->domestic_rejected_at?->toIso8601String(),
            'currency' => $order->currency,
            'owner' => $order->owner ? ['id' => $order->owner->id, 'name' => $order->owner->name] : null,
            'owner_label' => $order->owner ? 'Owner('.$order->owner->name.')' : null,
            'address' => $order->address?->only(['recipient_name', 'phone', 'country', 'province_state', 'city', 'address_line_1', 'address_line_2', 'postal_code']),
            'items' => $order->items->map(fn ($item): array => $item->only(['id', 'product_id', 'product_summary', 'sku', 'quantity', 'unit_price', 'line_revenue']))->values()->all(),
            'shipment' => $order->shipment ? [
                ...$order->shipment->only(['shipment_status', 'warehouse_id', 'warehouse_selection_source', 'carrier', 'service', 'carrier_selection_source', 'tracking_number', 'shipped_at', 'logistics_note', 'handled_by']),
                'warehouse_name' => $order->shipment->warehouse?->name,
            ] : null,
            'fulfillment' => $order->relationLoaded('fulfillmentChildren') ? $this->fulfillmentData($order) : null,
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

    /** @return array{code:string,en:string,zh:string,color:string,source:string,mutable:bool} */
    private function workflowProjection(Order $order): array
    {
        return $this->workflowProjectionService->for($order);
    }

    /** @return array<string,mixed> */
    private function fulfillmentData(Order $order): array
    {
        $originalChildren = $order->fulfillmentChildren
            ->filter(fn (Order $child): bool => (string) ($child->fulfillment_role ?? '') !== 'replacement')
            ->sortBy('fulfillment_suffix')->values();
        $replacements = $order->fulfillmentChildren
            ->filter(fn (Order $child): bool => (string) ($child->fulfillment_role ?? '') === 'replacement')
            ->sortBy(fn (Order $child): int => preg_match('/^R(\d+)$/', (string) $child->fulfillment_suffix, $m) ? (int) $m[1] : PHP_INT_MAX)
            ->values();
        $isSplit = $originalChildren->isNotEmpty();
        $originalSegments = $isSplit ? $originalChildren : collect([$order]);
        $allSegments = $originalSegments->concat($replacements)->values();
        $startedOriginalIds = $originalSegments->filter(fn (Order $segment): bool => $this->segmentLogisticsStarted($segment))->pluck('id')->all();
        $aggregate = $this->shipmentCompletion->aggregateForOrder($order);
        $fulfilledCount = $originalSegments->filter(
            fn (Order $segment): bool => $segment->shipment !== null && $this->shipmentCompletion->isFulfillmentComplete($segment->shipment)
        )->count();
        $hasException = $allSegments->contains(
            fn (Order $segment): bool => $segment->shipment?->shipment_status === 'exception'
        );

        return [
            'is_split' => $isSplit,
            'aggregate_status' => $aggregate,
            'aggregate_status_label' => $this->fulfillmentAggregateLabel($aggregate),
            'segment_count' => $allSegments->count(),
            'original_segment_count' => $originalSegments->count(),
            'replacement_count' => $replacements->count(),
            'fulfilled_count' => $fulfilledCount,
            'has_exception' => $hasException,
            'original_logistics_started' => $this->originalLogisticsStarted($order),
            'segments' => $allSegments->map(fn (Order $segment): array => [
                'order_id' => (int) $segment->getKey(),
                'erp_order_id' => $segment->erp_order_id,
                'suffix' => $segment->fulfillment_suffix,
                'role' => (string) ($segment->fulfillment_role ?? '') === 'replacement' ? 'replacement' : 'original',
                'role_label' => (string) ($segment->fulfillment_role ?? '') === 'replacement' ? '售后补发' : '原始履约',
                'reason' => $segment->fulfillment_reason,
                'reason_label' => $this->replacementReasonLabel($segment->fulfillment_reason),
                'origin_fulfillment_id' => $segment->fulfillment_origin_id,
                'origin_erp_order_id' => $segment->fulfillmentOrigin?->erp_order_id,
                'can_reallocate' => $isSplit
                    && (string) ($segment->fulfillment_role ?? '') !== 'replacement'
                    && ! $this->segmentLogisticsStarted($segment)
                    && collect($startedOriginalIds)->contains(fn ($id): bool => (int) $id !== (int) $segment->getKey()),
                'items' => $segment->items->map(fn ($item): array => [
                    'id' => (int) $item->id,
                    'sku' => $item->resolved_fulfillment_sku ?: $item->sku,
                    'product_summary' => $item->product_summary,
                    'quantity' => (int) $item->quantity,
                ])->values()->all(),
                'shipment' => $segment->shipment ? [
                    ...$segment->shipment->only(['shipment_status', 'warehouse_id', 'carrier', 'service', 'tracking_number', 'shipped_at', 'logistics_note']),
                    ...$this->warehouseReviewProjection($segment),
                    'status_label' => $this->fulfillmentSegmentStatusLabel(
                        $segment->shipment->shipment_status,
                        $segment->shipment->tracking_number,
                    ),
                    'warehouse_name' => $segment->shipment->warehouse?->name,
                ] : [
                    'shipment_status' => 'pending',
                    'warehouse_id' => null,
                    'carrier' => null,
                    'service' => null,
                    'tracking_number' => null,
                    'shipped_at' => null,
                    'logistics_note' => null,
                    'status_label' => '待发货',
                    'warehouse_name' => null,
                ],
            ])->all(),
        ];
    }

    /** @return array{review_required:bool,review_approved:bool,review_role:?string} */
    private function warehouseReviewProjection(Order $segment): array
    {
        $shipment = $segment->shipment;
        if (! $shipment || ! $shipment->warehouse_id) {
            return ['review_required' => false, 'review_approved' => false, 'review_role' => null];
        }

        $code = strtoupper(trim((string) $shipment->warehouse?->code));
        if (OverseaWarehouseReviewService::canonicalWarehouseCode($code) !== null) {
            return [
                'review_required' => true,
                'review_approved' => $shipment->overseaReview()->where('status', 'approved')
                    ->where('reviewed_warehouse_id', $shipment->warehouse_id)->exists(),
                'review_role' => 'Dede',
            ];
        }
        if ($code === 'FACTORY') {
            return [
                'review_required' => true,
                'review_approved' => $segment->operationalMarkers()->where('marker', 'domestic_reviewed')->exists()
                    && ! $segment->operationalMarkers()->where('marker', 'domestic_stockout')->exists()
                    && $segment->workflow_status !== 'out_of_stock',
                'review_role' => '国内',
            ];
        }

        return ['review_required' => false, 'review_approved' => false, 'review_role' => null];
    }

    private function originalLogisticsStarted(Order $order): bool
    {
        $originalChildren = $order->fulfillmentChildren
            ->filter(fn (Order $child): bool => (string) ($child->fulfillment_role ?? '') !== 'replacement')->values();
        $segments = $originalChildren->isNotEmpty() ? $originalChildren : collect([$order]);

        return $segments->contains(fn (Order $segment): bool => $this->segmentLogisticsStarted($segment));
    }

    private function segmentLogisticsStarted(Order $segment): bool
    {
        $shipment = $segment->shipment;
        if (! $shipment) {
            return false;
        }

        return $shipment->shipment_status !== 'pending' || filled($shipment->tracking_number) || $shipment->shipped_at !== null;
    }

    private function replacementReasonLabel(?string $reason): ?string
    {
        return match ($reason) {
            'lost' => '物流丢件',
            'damaged' => '产品损坏',
            'after_sales' => '售后换新',
            'maintenance' => '维护补件',
            'missing_item' => '少发漏发',
            'other' => '其他',
            default => null,
        };
    }

    private function fulfillmentAggregateLabel(string $status): string
    {
        return match ($status) {
            ShipmentCompletionSemantics::PartiallyShipped => '部分发货',
            ShipmentCompletionSemantics::Completed => '已发货',
            ShipmentCompletionSemantics::PartiallyDelivered => '部分送达',
            ShipmentCompletionSemantics::CompletedDelivered => '已完结',
            default => '待发货',
        };
    }

    private function fulfillmentSegmentStatusLabel(?string $status, ?string $trackingNumber): string
    {
        return match ($status) {
            'shipped' => filled($trackingNumber) ? '运输中' : '已发货待单号',
            'exception' => '异常',
            'completed' => '已完结',
            'cancelled' => '已取消',
            default => '待发货',
        };
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

    /** @return array<string, mixed> */
    private function itemAmendmentRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer', 'min:1'],
            'items.*.product_summary' => ['nullable', 'string', 'max:255'],
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
            'items.*.id' => ['nullable', 'integer', 'min:1'],
            'expected_revision' => ['sometimes', 'nullable', 'string', 'size:64'],
            'amendment_reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
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
