<?php

namespace App\Modules\Orders\Experience\Controllers;

use App\Modules\Audit\Infrastructure\Models\AuditRecord;
use App\Modules\Finance\Application\OrderAnalysisService;
use App\Modules\Identity\Application\UserDirectory;
use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use App\Modules\Kol\Application\KolAffiliateIdentity;
use App\Modules\Kol\Application\KolScope;
use App\Modules\Kol\Application\RecipientCustomsPolicy;
use App\Modules\Kol\Application\SavedViewService;
use App\Modules\Kol\Application\SmartInput\OnboardingDraft;
use App\Modules\Kol\Infrastructure\Models\Kol;
use App\Modules\Kol\Infrastructure\Models\KolCollaboration;
use App\Modules\Logistics\Application\CarrierRoutingPolicy;
use App\Modules\Logistics\Application\CarrierServiceCatalog;
use App\Modules\Logistics\Application\Domestic\DomesticReviewDecisionService;
use App\Modules\Logistics\Application\FulfillmentAllocationAccess;
use App\Modules\Logistics\Application\FulfillmentAllocationDecisionService;
use App\Modules\Logistics\Application\FulfillmentPlanPreviewService;
use App\Modules\Logistics\Application\LogisticsExecutionAccess;
use App\Modules\Logistics\Application\LogisticsReviewAccess;
use App\Modules\Logistics\Application\LogisticsTrackingListStatus;
use App\Modules\Logistics\Application\OverseaWarehouseReviewService;
use App\Modules\Logistics\Application\PendingAllocationEntryPolicy;
use App\Modules\Logistics\Application\ShipmentCompletionSemantics;
use App\Modules\Logistics\Application\WarehouseSkuPresentation;
use App\Modules\Orders\Application\KolOrderAccessScope;
use App\Modules\Orders\Application\ManualOrderItemAmendment;
use App\Modules\Orders\Application\OrderAuxiliaryStatusService;
use App\Modules\Orders\Application\OrderContactPolicy;
use App\Modules\Orders\Application\OrderContactProjection;
use App\Modules\Orders\Application\OrderItemAmendmentPreflight;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderLogisticsAuthority;
use App\Modules\Orders\Application\OrderOmsAvailability;
use App\Modules\Orders\Application\OrderPackagingReferenceProjection;
use App\Modules\Orders\Application\OrderProgressTransitionService;
use App\Modules\Orders\Application\OrderService;
use App\Modules\Orders\Application\OrderSourceType;
use App\Modules\Orders\Application\OrderWarehouseRouting;
use App\Modules\Orders\Application\OrderWorkflowStatus;
use App\Modules\Orders\Application\OrderWorkflowStatusPolicy;
use App\Modules\Orders\Application\OrderWorkflowStatusProjection;
use App\Modules\Orders\Application\OrderWorkflowStatusService;
use App\Modules\Orders\Application\WooCommerceAdminOrderLink;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderCollaborationPartner;
use Illuminate\Database\Eloquent\Builder;
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
        private readonly OrderProgressTransitionService $progressTransitions,
        private readonly OrderAuxiliaryStatusService $auxiliaryStatuses,
        private readonly OrderWorkflowStatusProjection $workflowProjectionService,
    ) {}

    public function index(Request $request): Response
    {
        $kind = $this->kindOrNull($request);
        $filters = $request->only(['search', 'source_type', 'order_status', 'workflow_status', 'view', 'warehouse_route_code']);
        $contactFilter = $request->query('grid_contact');
        $filters['contact_filter'] = is_string($contactFilter) ? mb_substr(trim($contactFilter), 0, 80) : '';
        $scope = (string) $request->route('order_scope');
        if ($scope === 'collaboration') {
            $filters['collaboration_scope'] = true;
            $filters['collaboration_partner_filter'] = $this->partnerFilter($request);
        }
        if ($scope === 'external_warehouse') {
            $filters['warehouse_ownership'] = 'external';
        }
        if ($kind) {
            $filters['order_kind'] = $kind->value;
            if ($kind === OrderKind::Customer) {
                $filters['customer_email_search'] = true;
            }
        }
        $filters = KolOrderAccessScope::scopeFilters($request->user(), $filters);
        // A Production canary must be explicitly enabled and individually allowlisted;
        // all other users retain the existing Orders list unconditionally.
        $omsGridAvailable = $kind === null && $scope !== 'external_warehouse' && $scope !== 'collaboration'
            && OrderOmsAvailability::grid($request->user());
        $omsGridEnabled = $omsGridAvailable && $request->boolean('oms_grid');
        $customerGlobalGridEnabled = ($kind === OrderKind::Customer || $scope === 'collaboration') && $request->query('legacy_grid') !== '1';
        $serverGridEnabled = $omsGridEnabled || $customerGlobalGridEnabled;
        $requestedPageSize = $serverGridEnabled ? (string) $request->query('per_page', '50') : '50';
        $perPage = in_array($requestedPageSize, ['20', '50', '100'], true) ? (int) $requestedPageSize : 50;
        $sort = $serverGridEnabled && in_array($request->query('sort'), ['order_number', 'date', 'source', 'status'], true)
            ? (string) $request->query('sort') : 'date';
        $direction = $serverGridEnabled && $request->query('direction') === 'asc' ? 'asc' : 'desc';
        $gridColumnFilters = [];
        if ($serverGridEnabled) {
            $filters['oms_sort'] = $sort;
            $filters['oms_direction'] = $direction;
            $filters['oms_numeric_contains'] = true;
            $gridColumnFilters = $this->gridColumnFilters($request);
            $filters['oms_grid_columns'] = $gridColumnFilters;
        }
        $orders = $this->orders->list($filters, $requestedPageSize === 'all' && $serverGridEnabled ? 200 : $perPage);
        $allRowsAllowed = $serverGridEnabled && $requestedPageSize === 'all' && $orders->total() <= 200;
        if ($requestedPageSize === 'all' && ! $allRowsAllowed) {
            $orders = $this->orders->list($filters, 50);
        }

        $isCustomerKind = $kind?->value === 'customer';
        $canExport = $isCustomerKind
            ? ($request->user()->can('customers.export') && $request->user()->can('system.permissions.manage'))
            : $request->user()->can('orders.view');

        $orders->getCollection()->loadMissing(['shipment.warehouse', 'manualSync', 'fulfillmentParent', 'latestNoteEntry', 'collaborationPartner', ...($customerGlobalGridEnabled ? ['customer'] : [])]);
        $trackingStatuses = app(LogisticsTrackingListStatus::class)->forShipments(
            $orders->getCollection()->map(fn (Order $order): int => (int) $order->shipment?->id)->all()
        );

        app(OrderContactProjection::class)->prime($orders->getCollection());

        return Inertia::render('Orders/Index', [
            'orderKind' => $kind?->value ?? 'all',
            'orderScope' => in_array($scope, ['external_warehouse', 'collaboration'], true) ? $scope : 'all',
            'carrierLabels' => collect($this->carrierServices->options())->mapWithKeys(fn (array $row): array => [$row['value'] => $row['label']])->all(),
            'orders' => $orders->through(fn (Order $order): array => $this->orderData($order, $trackingStatuses[(int) $order->shipment?->id] ?? null, $customerGlobalGridEnabled))->toArray(),
            'filters' => [
                'grid_contact' => $filters['contact_filter'],
                ...$request->only(['search', 'source_type', 'order_status', 'workflow_status', 'view', 'warehouse_route_code']),
                ...($scope === 'collaboration' ? ['partner_id' => $this->partnerFilter($request) ?? ''] : []),
                ...($serverGridEnabled ? ['sort' => $sort, 'direction' => $direction, 'per_page' => $allRowsAllowed ? 'all' : (string) $orders->perPage(), ...($omsGridEnabled ? ['oms_grid' => '1'] : []), ...$gridColumnFilters] : []),
            ],
            'omsGridAvailable' => $omsGridAvailable,
            'omsGridEnabled' => $omsGridEnabled,
            'customerGlobalGridEnabled' => $customerGlobalGridEnabled,
            'canExportOmsOrders' => $omsGridEnabled && OrderOmsAvailability::export($request->user()),
            'canConfirmOmsOrders' => ($omsGridEnabled || $scope === 'collaboration') && OrderOmsAvailability::import($request->user())
                && $request->user()->can('orders.create') && ! KolOrderAccessScope::isKolOnly($request->user()),
            'canImportOmsOrders' => ($omsGridEnabled || $scope === 'collaboration') && OrderOmsAvailability::importPreview($request->user())
                && $request->user()->can('orders.create') && ! KolOrderAccessScope::isKolOnly($request->user()),
            'workflowStatusOptions' => collect(OrderWorkflowStatus::registry())->map(fn (array $definition, string $code): array => [
                'code' => $code, ...$definition,
                'mutable' => ($definition['mutable'] || ($code === 'pending_allocation' && $kind === OrderKind::Customer))
                    && ($request->user()?->can('orders.edit') ?? false),
            ])->values()->all(),
            'savedViews' => $this->views->forUser('orders', (int) $request->user()->getAuthIdentifier(), false),
            'sourceTypes' => array_map(fn (OrderSourceType $source): array => ['value' => $source->value, 'label' => $this->sourceLabel($source)], OrderSourceType::cases()),
            'collaborationPartners' => $scope === 'collaboration' ? OrderCollaborationPartner::query()->orderBy('name')->get(['id', 'code', 'name']) : [],
            'canExport' => $canExport,
            'canEditWorkflowStatus' => OrderLogisticsAuthority::allows($request->user()),
            'canEditWarehouseRoute' => OrderLogisticsAuthority::allows($request->user()),
            'warehouseRouteOptions' => app(OrderWarehouseRouting::class)->options(),
            'canPreviewKolLinks' => $kind === OrderKind::KolFulfillment && $request->user()->can('kol.views.manage'),
            'canManageTrash' => $request->user()?->can('system.permissions.manage') ?? false,
        ]);
    }

    private function scopedKolSelection(Request $request): Builder
    {
        return Kol::query()->whereNull('archived_at')
            ->when(! KolScope::isAllScope($request->user()),
                fn (Builder $query) => $query->where('owner_id', $request->user()->getAuthIdentifier()))
            ->with([
                'socialAccounts' => fn ($query) => $query->where('is_active', true)->orderBy('id'),
                'affiliateAccounts' => fn ($query) => $query->where('connection_key', KolAffiliateIdentity::CONNECTION_KEY)->where('provider_status', 'active')->where('affiliate_id', '>', 0)->orderBy('id'),
            ]);
    }

    /** @return array{value: int, label: string, account: ?string} */
    private function kolSelectionData(Kol $kol): array
    {
        return [
            'value' => (int) $kol->id,
            'label' => $kol->display_name,
            'account' => $kol->socialAccounts->first()?->handle
                ?: $kol->affiliateAccounts->first()?->wordpress_user_login,
        ];
    }

    /** Exact KOL address projection; never fetch an unrelated owner's private address. */
    private function kolShippingData(Kol $kol): array
    {
        return [
            'recipient_name' => $kol->shipping_recipient_name ?: $kol->legal_name ?: $kol->display_name,
            'phone' => $kol->contact_phone ?: $kol->contact_whatsapp,
            'email' => $kol->contact_email,
            'recipient_customs_id' => RecipientCustomsPolicy::showsRecipientId($kol->shipping_country) ? $kol->shipping_customs_id : null,
            'country' => $kol->shipping_country,
            'province_state' => $kol->shipping_province_state,
            'city' => $kol->shipping_city,
            'address_line_1' => $kol->shipping_address_line_1,
            'address_line_2' => $kol->shipping_address_line_2,
            'postal_code' => $kol->shipping_postal_code,
        ];
    }

    public function kolAddress(Request $request): JsonResponse
    {
        KolOrderAccessScope::authorizeCreate($request->user(), OrderKind::KolFulfillment);
        abort_unless($request->user()->can('kol.view'), 403);
        $data = $request->validate(['kol_id' => ['required', 'integer', 'min:1']]);
        $kol = $this->scopedKolSelection($request)->findOrFail((int) $data['kol_id']);

        return response()->json(['kol_id' => (int) $kol->id, 'address' => $this->kolShippingData($kol), 'contact_user_id' => OrderContactPolicy::isEligible(OrderKind::KolFulfillment, $kol->owner_id) ? (int) $kol->owner_id : null]);
    }

    public function searchKolOptions(Request $request): JsonResponse
    {
        KolOrderAccessScope::authorizeCreate($request->user(), OrderKind::KolFulfillment);
        abort_unless($request->user()->can('kol.view'), 403);
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $term = trim((string) ($validated['q'] ?? ''));
        $accountTerm = ltrim($term, '@');

        // A pasted full IG/WordPress handle should not wait for the broad multi-table
        // substring scan. Preserve the stored spelling, symbols and underscores.
        // Numeric queries retain the existing ID-first + fuzzy-results contract.
        if ($term !== '' && ! ctype_digit($term)) {
            // Use independent handle lookups so ordinary indexes can be used.
            // A correlated OR/EXISTS over all KOLs costs more even for an exact match.
            $accountKolIds = DB::table('kol_social_accounts')->select('kol_id')
                ->where('is_active', true)->where('handle', $accountTerm)
                ->union(DB::table('kol_affiliate_accounts')->select('kol_id')
                    ->where('wordpress_user_login', $accountTerm))
                ->pluck('kol_id')->all();

            $exactOptions = $this->scopedKolSelection($request)
                ->where(function (Builder $builder) use ($term, $accountKolIds): void {
                    $builder->where('display_name', $term)->orWhere('contact_email', $term);
                    if ($accountKolIds !== []) {
                        $builder->orWhereIn('id', $accountKolIds);
                    }
                })
                ->orderBy('display_name')->orderBy('id')->limit(20)->get(['id', 'display_name'])
                ->map(fn (Kol $kol): array => $this->kolSelectionData($kol))->all();

            if ($exactOptions !== []) {
                return response()->json($exactOptions);
            }
        }

        $query = $this->scopedKolSelection($request);
        if ($term !== '') {
            // A username may contain '_' or '%': treat them as literal characters,
            // not SQL LIKE wildcards. '!' is portable to MySQL and SQLite as ESCAPE.
            $escapeLike = static fn (string $value): string => str_replace(
                ['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($value)
            );
            $pattern = '%'.$escapeLike($term).'%';
            $accountPattern = '%'.$escapeLike($accountTerm).'%';
            $query->where(function (Builder $builder) use ($term, $pattern, $accountPattern): void {
                $builder->whereRaw("display_name LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("contact_email LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('socialAccounts', fn (Builder $accounts) => $accounts
                        ->where('is_active', true)->whereRaw("handle LIKE ? ESCAPE '!'", [$accountPattern]))
                    ->orWhereHas('affiliateAccounts', fn (Builder $accounts) => $accounts
                        ->whereRaw("wordpress_user_login LIKE ? ESCAPE '!'", [$accountPattern]));
                if (ctype_digit($term)) {
                    $builder->orWhere('id', (int) $term);
                }
            });
        }

        // An exact numeric KOL ID must remain visible even among many partial text matches.
        if (ctype_digit($term)) {
            $query->orderByRaw('CASE WHEN kols.id = ? THEN 0 ELSE 1 END', [(int) $term]);
        }
        $options = $query->orderBy('display_name')->orderBy('id')->limit(20)->get(['id', 'display_name'])
            ->map(fn (Kol $kol): array => $this->kolSelectionData($kol))->all();

        return response()->json($options);
    }

    public function create(Request $request): Response
    {
        $kind = $this->kind($request);
        KolOrderAccessScope::authorizeCreate($request->user(), $kind);
        $requestedKolId = $request->integer('kol_id');
        $requestedKol = $kind === OrderKind::KolFulfillment && $request->user()->can('kol.view') && $requestedKolId > 0
            ? $this->scopedKolSelection($request)->find($requestedKolId)
            : null;
        $kolOptions = $requestedKol ? [$this->kolSelectionData($requestedKol)] : [];
        $selectedKolId = $requestedKol ? $requestedKolId : null;

        return Inertia::render('Orders/Create', [
            'defaultOrderKind' => $kind->value,
            'orderKindLabel' => $this->kindLabel($kind),
            'generatedExternalOrderNumber' => $this->orders->generateExternalOrderNumber($kind),
            'kolOptions' => $kolOptions,
            'selectedKolId' => $selectedKolId,
            'contactOptions' => $kind === OrderKind::KolFulfillment ? OrderContactPolicy::options($kind) : OrderContactPolicy::manualOptions(),
            'defaultCustomerContactId' => $kind === OrderKind::Customer ? OrderContactPolicy::operationsDefault((int) $request->user()->getAuthIdentifier()) : null,
            'selectedKolContactId' => $requestedKol && OrderContactPolicy::isEligible($kind, $requestedKol->owner_id) ? (int) $requestedKol->owner_id : null,
            'selectedKolAddress' => $requestedKol ? $this->kolShippingData($requestedKol) : null,
            'onboardingToken' => $requestedKol && OnboardingDraft::readForKol($request, $request->query('onboarding_token'), $requestedKolId) ? $request->query('onboarding_token') : null,
            'resumeOrderDraft' => $requestedKol ? OnboardingDraft::readForKol($request, $request->query('onboarding_token'), $requestedKolId) : null,
            'canOnboardKol' => $kind === OrderKind::KolFulfillment && $request->user()->can('kol.operate') && $request->user()->can('kol.view'),
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
        $token = $request->input('onboarding_token');
        if ($kind === OrderKind::KolFulfillment && $token !== null) {
            abort_unless(is_string($token) && OnboardingDraft::readForKol($request, $token, $request->integer('kol_id')), 422, '订单草稿已失效，请重新发起。');
            $request->validate(['address_confirmed' => ['accepted']]);
        }
        if ($kind === OrderKind::KolFulfillment && $request->input('address_confirmed') !== null) {
            $request->validate(['address_confirmed' => ['accepted']]);
        }
        $orderRules = $this->rules();
        if ($kind === OrderKind::KolFulfillment) {
            $orderRules['order_date'] = ['nullable', 'date'];
        }
        $data = $request->validate([
            ...$orderRules,
            'contact_user_id' => $kind === OrderKind::KolFulfillment ? ['required', 'integer', 'min:1'] : ['nullable', 'integer', 'min:1'],
            'kol_id' => $kind === OrderKind::KolFulfillment
                ? ['required', 'integer', Rule::exists('kols', 'id')->whereNull('archived_at')]
                : ['prohibited'],
        ]);
        if ($kind === OrderKind::KolFulfillment && ! OrderContactPolicy::isEligible($kind, (int) $data['contact_user_id'])) {
            throw ValidationException::withMessages(['contact_user_id' => '请选择有效的网红联络人（负责人）。']);
        }
        if ($kind === OrderKind::Customer && isset($data['contact_user_id']) && ! OrderContactPolicy::isManualEligible((int) $data['contact_user_id'])) {
            throw ValidationException::withMessages(['contact_user_id' => '请选择有效的在职员工。']);
        }
        if ($kind === OrderKind::KolFulfillment && filled($data['kol_id'] ?? null)) {
            abort_unless($request->user()->can('kol.view'), 403);
            $kol = Kol::query()->findOrFail($data['kol_id']);
            abort_unless(KolScope::isAllScope($request->user()) || (int) $kol->owner_id === (int) $request->user()->getAuthIdentifier(), 403);
            // Reuse canonical KOL shipping/contact data when not explicitly supplied on the order.
            foreach ($this->kolShippingData($kol) as $field => $value) {
                // A saved personal ID must never be copied to an order for a different country.
                if ($field === 'recipient_customs_id' && filled($data['country'] ?? null)
                    && RecipientCustomsPolicy::code($data['country']) !== RecipientCustomsPolicy::code($kol->shipping_country)) {
                    continue;
                }
                if (! $request->boolean('address_confirmed') && blank($data[$field] ?? null) && filled($value)) {
                    $data[$field] = $value;
                }
            }
            if (! RecipientCustomsPolicy::showsRecipientId($data['country'] ?? null)) {
                $data['recipient_customs_id'] = null;
            }
        } else {
            unset($data['kol_id']);
        }
        if ($kind === OrderKind::KolFulfillment && RecipientCustomsPolicy::requiresRecipientId($data['country'] ?? null)
            && blank($data['recipient_customs_id'] ?? null)) {
            throw ValidationException::withMessages([
                'recipient_customs_id' => '该收件国家需要收件人清关税号或身份证号码，请填写后创建订单。',
            ]);
        }
        if ($kind === OrderKind::KolFulfillment && blank($data['order_date'] ?? null)) {
            $data['order_date'] = now()->toDateString();
        }
        $items = $data['items'];
        unset($data['items']);
        // Route context is authority: order_kind/source_type/owner cannot be overridden by client input.
        $data['order_kind'] = $this->kind($request)->value;
        $data['source_type'] = OrderSourceType::Manual->value;
        $data['currency'] ??= 'USD';
        $data['owner_id'] = $request->user()->getAuthIdentifier();
        if ($kind === OrderKind::Customer) {
            $data['contact_user_id'] ??= OrderContactPolicy::operationsDefault((int) $data['owner_id']);
        }
        $order = $this->orders->create($data, $items, $request->user());
        if ($kind === OrderKind::KolFulfillment && is_string($token)) {
            OnboardingDraft::forget($request);
        }

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
        $order->load(['items', 'owner', 'address', 'shipment.warehouse', 'manualSync', 'financials', 'operationalMarkers', 'procurementOrder', 'fulfillmentAllocation', 'fulfillmentParent', 'fulfillmentChildren.items', 'fulfillmentChildren.shipment.warehouse', 'fulfillmentChildren.fulfillmentOrigin']);
        $productIds = $order->items->pluck('product_id')->filter()->unique()->values()->all();
        $inventoryAvailability = $this->availability->forProductIds($productIds);
        $warehouses = $this->availability->activeWarehouses();
        $allocationUi = $isDerived ? null : $this->allocationUi($request, $order, $productIds, $warehouses, $inventoryAvailability);

        app(OrderContactProjection::class)->prime([$order, ...$order->fulfillmentChildren]);
        $canEditContact = in_array($authority->order_kind, [OrderKind::Customer, OrderKind::KolFulfillment], true) && ! $isDerived && ! $order->trashed() && $order->archived_at === null && ! $order->isOperationallyArchived()
            && OrderContactPolicy::canEdit($request->user());

        return Inertia::render('Orders/Show', [
            'canEditContact' => $canEditContact,
            'contactOptions' => $canEditContact ? OrderContactPolicy::manualOptions() : [],
            'order' => $this->orderData($order),
            'warehouseRouteOptions' => app(OrderWarehouseRouting::class)->options(),
            'canEditWarehouseRoute' => ! $order->isOperationallyArchived() && $order->archived_at === null
                && OrderLogisticsAuthority::allows($request->user()),
            'orderNotes' => $order->noteEntries()->get()->map(fn ($entry): array => [
                'id' => $entry->id, 'author_name' => $entry->author_name,
                'body' => $entry->body, 'created_at' => $entry->created_at?->toIso8601String(),
            ])->all(),
            'canAppendOrderNote' => ! $order->isOperationallyArchived()
                && $order->archived_at === null && $authority->archived_at === null
                && ! $authority->isOperationallyArchived(),
            'orderNavigation' => $this->adjacentOrderNavigation($request, $order),
            'wooAdminEditUrl' => $isDerived ? null : $this->wooAdminLink->forOrder($order),
            'inventoryAvailability' => $inventoryAvailability,
            'allocationUi' => $allocationUi,
            'packagingReferences' => $this->packagingReferences->forOrder($order),
            'warehouses' => $warehouses,
            'warehouseRouting' => collect($warehouses)->mapWithKeys(function (array $warehouse) use ($order): array {
                $code = strtoupper(trim((string) ($warehouse['code'] ?? '')));
                // Factory dispatch carrier/service are confirmed after domestic review.
                $route = $code === 'FACTORY' ? []
                    : $this->carrierRouting->warehouseRoute($order->address?->country, $code);

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
            'isDomesticFactory' => strtoupper(trim((string) $order->shipment?->warehouse?->code)) === 'FACTORY',
            'canMarkDomesticStockout' => ! $order->isOperationallyArchived()
                && ($request->user()?->can('domestic.review') ?? false)
                && strtoupper(trim((string) $order->shipment?->warehouse?->code)) === 'FACTORY'
                && $order->order_status?->value === 'pending_shipment'
                && $order->shipment?->shipment_status === 'pending'
                && blank($order->shipment?->tracking_number)
                && $order->shipment?->shipped_at === null,
            'workflowStatusOptions' => app(OrderWorkflowStatusPolicy::class)->for($order, $request->user())['options'],
            'canEditOrder' => ! $order->isOperationallyArchived() && $order->archived_at === null
                && ($request->user()?->can('orders.content.update') ?? false),
            'canSyncChildAddresses' => ! $isDerived && $order->originalFulfillmentChildren()->exists()
                && ! $order->isOperationallyArchived() && $order->archived_at === null
                && ($request->user()?->can('orders.content.update') ?? false),
            'canEditWorkflowStatus' => app(OrderWorkflowStatusPolicy::class)->for($order, $request->user())['can_edit'],
            'canManageAuxiliary' => ! $order->isOperationallyArchived() && ($request->user()?->can('orders.edit') ?? false),
            'canTrashOrder' => ! $isDerived && ! $order->isOperationallyArchived() && ($request->user()?->can('orders.edit') ?? false),
            'canArchiveOrder' => ! $order->trashed() && $order->archived_at === null && $order->operational_archived_at === null && ($request->user()?->can('orders.archive') ?? false),
            'directlyArchived' => $order->operational_archived_at !== null,
            'operationallyArchived' => $order->isOperationallyArchived(),

            'canManageTrash' => $request->user()?->can('system.permissions.manage') ?? false,
            'canCreateReplacement' => ! $isDerived && $this->logisticsExecutionAccess->allows($request->user()) && $this->originalLogisticsStarted($order),
            'canCreateProcurement' => ! $isDerived && ($request->user()?->can('procurement.create') ?? false) && $order->operationalMarkers->contains('marker', 'stockout'),
            'canManageProductPackaging' => ! $isDerived && ($request->user()?->can('products.operate') ?? false),
            'canForceTrackingSync' => ($request->user()?->can('sync.operate') ?? false),
            'canOperateLogistics' => $this->logisticsExecutionAccess->allows($request->user()),
            'canReopenFulfillment' => $this->fulfillmentAllocationAccess->allows($request->user()),
            'canViewPackagingEvidence' => ($request->user()?->can('inventory.view') ?? false) || ($request->user()?->can('inventory.packaging.review') ?? false),
            'audit' => $this->orderAudit($order),
        ]);
    }

    private function orderAudit(Order $order): array
    {
        $records = AuditRecord::query()
            ->where('entity_type', Order::class)
            ->where('entity_id', (string) $order->getKey())
            ->latest('created_at')->orderByDesc('id')->limit(30)->get();
        $names = app(UserDirectory::class)->namesForIds($records->pluck('actor_id'));

        return $records->map(fn (AuditRecord $record): array => [
            'id' => $record->id,
            'action' => $record->action,
            'actor_id' => $record->actor_id,
            'actor_name' => $record->actor_id !== null ? ($names[(string) $record->actor_id] ?? null) : null,
            'created_at' => $record->created_at?->toIso8601String(),
            'reason' => $record->reason,
        ])->all();
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
            ? $order->fulfillmentChildren->filter(fn (Order $child): bool => in_array($child->fulfillment_role, [null, 'original'], true))->values()
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
        // Presence is forbidden even for empty/null values: Laravel's `prohibited`
        // validator alone treats empty values as absent, risking legacy note erasure.
        if (array_key_exists('order_note', $request->all())) {
            throw ValidationException::withMessages(['order_note' => '历史备注不可修改，请在内部协作记录中追加备注。']);
        }
        $data = $request->validate($this->updateRules($order));
        $items = $data['items'] ?? null;
        $reason = trim((string) ($data['amendment_reason'] ?? ''));
        unset($data['items'], $data['amendment_reason'], $data['expected_revision']);

        // Lock the order while validating and refreshing its current fulfillment.
        DB::transaction(function () use ($request, $order, $data, $items, $reason): void {
            $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            abort_if($lockedOrder->isOperationallyArchived(), 409, '运营归档订单为只读，不能编辑。');
            if ($items !== null) {
                $preview = $this->itemAmendmentPreflight->preview($lockedOrder, $items, false);
                if (filled($request->input('expected_revision')) && $request->input('expected_revision') !== $preview['expected_revision']) {
                    abort(409, '订单商品已被其他操作修改，请刷新订单后重试。');
                }
                if (! $preview['can_save_direct']) {
                    throw ValidationException::withMessages(['items' => $preview['issues'][0] ?? '订单商品不能直接修改。']);
                }
            }
            $amendment = app(ManualOrderItemAmendment::class);
            $before = $items !== null && $preview['changed'] ? $lockedOrder->items()->get()->keyBy('id') : null;
            $updated = $this->orders->update($lockedOrder, $data, $items, $request->user(), $reason ?: null);
            if ($before !== null) {
                $amendment->refresh($updated, $before, $request->user());
            }
        });

        return back()->with('success', '订单已更新');
    }

    public function setOperationalMarker(Request $request, Order $order, string $marker): RedirectResponse
    {
        KolOrderAccessScope::authorize($request->user(), $order);
        abort_if($order->isOperationallyArchived(), 409, '运营归档订单为只读，不能修改业务标记。');
        $data = $request->validate(['active' => ['required', 'boolean'], 'expected_workflow_revision' => ['nullable', 'integer', 'min:0']]);
        if (in_array($marker, ['exception', 'stockout'], true)) {
            $order->loadMissing('shipment.warehouse');
            $factoryStockout = $marker === 'stockout'
                && strtoupper(trim((string) $order->shipment?->warehouse?->code)) === 'FACTORY';
            // Preserve the legacy factory marker's explicit restock safety gate;
            // the reviewed workflow dropdown uses its own audited restock action.
            if ($factoryStockout && ! $data['active']) {
                abort(409, '请在国内「缺货单」点击「已到货-可发货」恢复。');
            }
            if ($marker === 'stockout' && $data['active'] && $factoryStockout) {
                abort_unless($request->user()->can('domestic.review'), 403);
                app(DomesticReviewDecisionService::class)
                    ->decide($order, 'stockout', null, $request->user());
            } elseif ($marker === 'stockout' && $order->shipment?->warehouse
                && OverseaWarehouseReviewService::canonicalWarehouseCode($order->shipment->warehouse->code) !== null) {
                abort_unless(app(LogisticsReviewAccess::class)->allows($request->user()), 403);
                $reviews = app(OverseaWarehouseReviewService::class);
                $data['active'] ? $reviews->stockout($order, $request->user())
                    : $reviews->restock($order, $request->user());
            } else {
                // A warehouse-review shortcut is a PHYSICAL action above.
                // An order-status dropdown changes only the manual main state.
                $next = $marker === 'exception'
                    ? ($data['active'] ? 'exception' : 'resolve_exception')
                    : ($data['active'] ? 'out_of_stock' : 'resolve_stockout');
                $this->progressTransitions->transition($order, $next,
                    (int) ($data['expected_workflow_revision'] ?? $order->workflow_revision), $request->user());
            }

            return back()->with('success', $factoryStockout
                ? '已标记缺货，订单进入国内「缺货单」。'
                : '订单核心状态已更新');
        }

        $this->orders->setOperationalMarker($order, $marker, (bool) $data['active'], $request->user());

        return back()->with('success', '订单业务标记已更新');
    }

    public function updateWorkflowStatus(Request $request, Order $order): JsonResponse
    {
        KolOrderAccessScope::authorize($request->user(), $order);
        abort_if($order->trashed(), 422, '已删除的订单请先从回收站恢复。');
        $trashArchived = $order->archived_at !== null;
        if ($trashArchived) {
            // Match the existing trash-restore authority; a status edit must not
            // silently give ordinary editors permission to recover trash records.
            abort_unless($request->user()?->can('orders.edit')
                && $request->user()?->can('system.permissions.manage'), 403);
        }
        $reactivating = $trashArchived || $order->isOperationallyArchived();
        if ($reactivating) {
            abort_unless($request->user()?->can('orders.edit'), 403);
        }
        abort_unless(OrderLogisticsAuthority::allows($request->user()) || $request->user()?->can('domestic.review')
            || app(LogisticsReviewAccess::class)->allows($request->user()), 403);

        $data = $request->validate([
            'workflow_status' => ['required', 'string', Rule::in([
                ...array_keys(array_filter(OrderWorkflowStatus::registry(), fn (array $definition): bool => $definition['mutable'])),
                'pending_allocation', 'resolve_exception', 'resolve_manual_hold', 'resolve_stockout', \App\Modules\Orders\Application\DomesticShippingHold::RESUME,
            ])],
            'expected_workflow_revision' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        if (! OrderLogisticsAuthority::allows($request->user())) {
            abort_unless(in_array($data['workflow_status'], ['sending', 'out_of_stock', 'resolve_stockout'], true), 403);
            $order->loadMissing('shipment.warehouse');
            $factory = strtoupper(trim((string) $order->shipment?->warehouse?->code)) === 'FACTORY';
            $overseas = OverseaWarehouseReviewService::canonicalWarehouseCode($order->shipment?->warehouse?->code) !== null;
            abort_unless(($factory && $request->user()->can('domestic.review'))
                || ($overseas && app(LogisticsReviewAccess::class)->allows($request->user())
                    && in_array($data['workflow_status'], ['out_of_stock', 'resolve_stockout'], true)), 403);
        }
        if ($reactivating && (\App\Modules\Orders\Application\DomesticShippingHold::isHeld($data['workflow_status'])
            || $data['workflow_status'] === \App\Modules\Orders\Application\DomesticShippingHold::RESUME)) {
            abort(409, '归档订单不能通过冻结发货操作恢复。');
        }
        $updated = $reactivating
            ? $this->progressTransitions->reactivateAndTransition($order, $data['workflow_status'], $data['expected_workflow_revision'], $request->user(), $data['reason'] ?? null)
            : $this->progressTransitions->transition($order, $data['workflow_status'], $data['expected_workflow_revision'], $request->user(), $data['reason'] ?? null);

        return response()->json(['order' => $this->orderData($updated), 'workflow_status' => $this->workflowProjection($updated),
            'target_url' => app(OrderWarehouseRouting::class)->destination($updated, $request->user())]);
    }

    public function removeAuxiliaryStatus(Request $request, Order $order, string $code): JsonResponse
    {
        KolOrderAccessScope::authorize($request->user(), $order);
        abort_unless($request->user()?->can('orders.edit'), 403);
        abort_if($order->isOperationallyArchived(), 409, '归档订单不可修改辅助标记。');
        $data = $request->validate(['expected_workflow_revision' => ['required', 'integer', 'min:0']]);
        $updated = $this->auxiliaryStatuses->remove($order, $code, (int) $data['expected_workflow_revision'], $request->user());

        return response()->json(['order' => $this->orderData($updated), 'workflow_status' => $this->workflowProjection($updated),
            'target_url' => app(OrderWarehouseRouting::class)->destination($updated, $request->user())]);
    }

    public function export(Request $request): StreamedResponse
    {
        $kind = $this->kindOrNull($request);
        $filters = $request->only(['search', 'source_type', 'order_status', 'workflow_status', 'view', 'warehouse_route_code']);
        $scope = (string) $request->route('order_scope');
        if ($scope === 'collaboration') {
            $filters['collaboration_scope'] = true;
            $filters['collaboration_partner_filter'] = $this->partnerFilter($request);
        }
        if ($scope === 'external_warehouse') {
            $filters['warehouse_ownership'] = 'external';
        }
        if ($kind) {
            $filters['order_kind'] = $kind->value;
        }
        $includeCustomerEmail = $kind === OrderKind::Customer;
        if ($includeCustomerEmail) {
            $filters['customer_email_search'] = true;
            if ($request->query('legacy_grid') !== '1') {
                $filters['oms_grid_columns'] = $this->gridColumnFilters($request);
                $filters['oms_numeric_contains'] = true;
                $filters['oms_sort'] = in_array($request->query('sort'), ['order_number', 'date', 'source', 'status'], true)
                    ? (string) $request->query('sort') : 'date';
                $filters['oms_direction'] = $request->query('direction') === 'asc' ? 'asc' : 'desc';
            }
        }
        $filters = KolOrderAccessScope::scopeFilters($request->user(), $filters);
        $includePartner = $scope === 'collaboration';
        if ($includePartner && $request->query->has('order_ids')) {
            // Same explicit-ID download contract as domestic pending shipments.
            // Never fall back to the full filtered export for an empty or stale selection.
            $selected = $request->query('order_ids');
            abort_unless(is_array($selected) && array_is_list($selected) && count($selected) >= 1 && count($selected) <= 200, 422, '请勾选 1–200 笔合作订单。');
            $ids = [];
            foreach ($selected as $id) {
                abort_unless((is_string($id) || is_int($id)) && filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false, 422, '订单 ID 无效。');
                $ids[] = (int) $id;
            }
            abort_unless(count(array_unique($ids)) === count($ids), 422, '已选订单不能重复。');
            $filters['oms_selected_ids'] = $ids;
            abort_unless($this->orders->list($filters, 200)->total() === count($ids), 422, '部分已选订单已不可见或不在合作订单范围内，请重新勾选。');
        }
        $orders = ($includeCustomerEmail || $includePartner) ? $this->orders->exportBatched($filters) : $this->orders->exportCursor($filters);
        $partnerNames = $includePartner ? OrderCollaborationPartner::query()->pluck('name', 'id')->all() : [];

        return response()->streamDownload(function () use ($orders, $includeCustomerEmail, $includePartner, $partnerNames): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'ERP订单号', '来源订单号', '来源', ...($includePartner ? ['合作商'] : []), '订单日期', '收件人',
                ...($includeCustomerEmail ? ['客户邮箱'] : []), '国家', '状态', '负责人',
                ...($includePartner ? ['物流商', '发货单号'] : []),
            ]);
            foreach ($orders as $order) {
                fputcsv($handle, [
                    $order->erp_order_id, $order->external_order_number, $this->sourceLabel($order->source_type),
                    ...($includePartner ? [$partnerNames[$order->collaboration_partner_id] ?? '待确认'] : []),
                    $order->order_date?->toDateString(), $order->address?->recipient_name,
                    ...($includeCustomerEmail ? [$order->address?->email ?: $order->customer?->email] : []),
                    $order->address?->country, $order->order_status->value, $order->owner?->name,
                    ...($includePartner ? [$order->shipment?->carrier, $order->shipment?->tracking_number] : []),
                ]);
            }
            fclose($handle);
        }, 'orders.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** The same allowlisted server-side column filters power list, saved views and CSV export. */
    private function gridColumnFilters(Request $request): array
    {
        $columns = [];
        foreach (['grid_order_number', 'grid_sku', 'grid_quantity', 'grid_product_names', 'grid_source', 'grid_date', 'grid_recipient', 'grid_country', 'grid_warehouse', 'grid_status', 'grid_tracking', 'grid_owner', 'grid_city', 'grid_postal_code', 'grid_carrier', 'grid_external_number', 'grid_email'] as $key) {
            $raw = $request->query($key);
            if (is_string($raw)) {
                $value = mb_substr(trim($raw), 0, 80);
                if ($value !== '') {
                    $columns[$key] = $value;
                }
            }
        }

        return $columns;
    }

    private function partnerFilter(Request $request): ?string
    {
        $filter = $request->query('partner_id');
        if (! is_string($filter) || $filter === '') {
            return null;
        }
        if ($filter === 'unassigned') {
            return $filter;
        }
        abort_unless(ctype_digit($filter) && (int) $filter > 0, 422, '合作商筛选参数无效');

        return $filter;
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

    private function orderData(Order $order, ?array $trackingOutbox = null, bool $includeCustomerEmail = false): array
    {
        $workflowProjection = $this->workflowProjection($order);
        $workflowPolicy = app(OrderWorkflowStatusPolicy::class)->for($order, auth()->user());
        $trackingSource = $order->fulfillment_parent_id ? $order->fulfillmentParent?->source_type : $order->source_type;
        $trackingSourceValue = $trackingSource instanceof \BackedEnum ? $trackingSource->value : (string) $trackingSource;
        $trackingSync = $order->shipment && $trackingSourceValue === 'woocommerce'
            ? app(LogisticsTrackingListStatus::class)->currentStatus($trackingOutbox, $order->manualSync?->website_sync_status)
            : ['status' => null, 'reason' => null];

        return [
            'id' => $order->id,
            'internal_order_number' => $order->internal_order_number,
            'erp_order_id' => $order->erp_order_id,
            'external_order_number' => $order->external_order_number,
            'source_order_status' => $order->relationLoaded('financials') ? $order->financials?->provider_order_status : null,
            'source_order_status_modified_at' => $order->relationLoaded('financials') ? $order->financials?->provider_modified_at?->toIso8601String() : null,
            'source_type' => $order->source_type->value,
            'source_label' => $this->sourceLabel($order->source_type),
            'collaboration_partner' => $order->collaborationPartner?->only(['id', 'code', 'name']),
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
            'can_edit_workflow_status' => $workflowPolicy['can_edit'],
            'workflow_status_options' => $workflowPolicy['options'],
            'routing_warehouse' => app(OrderWarehouseRouting::class)->project($order),
            'order_note' => $order->order_note,
            // One latest visible note; collaborative entries win over the legacy/source note.
            'latest_order_note' => $order->latestNoteEntry?->body ?: $order->order_note,
            'domestic_reject_reason' => $order->domestic_reject_reason,
            'domestic_rejected_at' => $order->domestic_rejected_at?->toIso8601String(),
            'currency' => $order->currency,
            'owner' => $order->owner ? ['id' => $order->owner->id, 'name' => $order->owner->name] : null,
            'owner_label' => $order->owner ? 'Owner('.$order->owner->name.')' : null,
            ...app(OrderContactProjection::class)->for($order),
            'customer_email' => $includeCustomerEmail ? ($order->address?->email ?: $order->customer?->email) : null,
            'address' => $order->address?->only(['recipient_name', 'phone', 'country', 'province_state', 'city', 'address_line_1', 'address_line_2', 'postal_code', 'recipient_customs_id']),
            'items' => $order->items->map(fn ($item): array => [
                ...$item->only(['id', 'product_id', 'product_summary', 'quantity', 'unit_price', 'line_revenue']),
                'sku' => WarehouseSkuPresentation::sku($item),
                'source_sku' => $item->source_sku ?: $item->sku,
                'final_sku' => $item->final_sku,
            ])->values()->all(),
            'tracking_sync_status' => $trackingSync['status'],
            'tracking_sync_reason' => $trackingSync['reason'],
            'tracking_sync_verified_at' => $trackingSync['verified_at'] ?? null,
            'tracking_sync_label' => match ($trackingSync['status']) {
                'completed' => '已同步', 'failed' => '失败', 'retry_wait' => '待重试', 'processing' => '同步中', default => $trackingSourceValue === 'woocommerce' && filled($order->shipment?->tracking_number) ? '待同步' : '—'
            },
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
            ->filter(fn (Order $child): bool => in_array($child->fulfillment_role, [null, 'original'], true))
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
                ...app(OrderContactProjection::class)->for($segment),
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
                    'sku' => WarehouseSkuPresentation::sku($item),
                    'final_sku' => $item->final_sku,
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
            ->filter(fn (Order $child): bool => in_array($child->fulfillment_role, [null, 'original'], true))->values();
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
            'recipient_name' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:64'], 'email' => ['nullable', 'email', 'max:255'],
            'country' => ['nullable', 'string', 'max:128'], 'province_state' => ['nullable', 'string', 'max:128'],
            'city' => ['nullable', 'string', 'max:128'], 'address_line_1' => ['nullable', 'string', 'max:255'],
            'recipient_customs_id' => ['nullable', 'string', 'max:128'],
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
            // Website/legacy note is read-only after creation; collaboration uses append-only entries.
            'order_note' => ['prohibited'],
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
            OrderSourceType::Collaboration => '合作订单',
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
