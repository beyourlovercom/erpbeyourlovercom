<?php

namespace App\Modules\Sync\Experience\Controllers;

use App\Modules\Logistics\Application\LogisticsTrackingListStatus;
use App\Modules\Logistics\Application\LogisticsTrackingPushAccess;
use App\Modules\Orders\Application\Inbound\WooOrderManualSyncAccess;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\ManualSyncService;
use App\Modules\Sync\Application\OrderSyncProgressProjector;
use App\Modules\Sync\Application\OrderSyncProgressSnapshot;
use App\Modules\Sync\Application\SyncOrchestrationService;
use App\Modules\Sync\Application\WooOrderSyncHealthReader;
use App\Modules\Sync\Infrastructure\Models\SyncOutbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class ManualSyncController
{
    public function __construct(
        private readonly ManualSyncService $sync,
        private readonly SyncOrchestrationService $orchestration,
    ) {}

    public function index(Request $request): Response
    {
        // This is an ALL-ORDERS ledger. Do not require a manual_sync row:
        // that omitted unsynced orders from the previous implementation.
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:128'],
            'source' => ['nullable', Rule::in(['woocommerce', 'website', 'kol', 'manual', 'manual_other'])],
            'per_page' => ['nullable', Rule::in([20, 50, 100, '20', '50', '100'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in(['date', 'order_number', 'source'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'grid_order_number' => ['nullable', 'string', 'max:128'],
            'grid_date' => ['nullable', 'string', 'max:32'],
            'grid_source' => ['nullable', 'string', 'max:64'],
            'grid_recipient' => ['nullable', 'string', 'max:128'],
            'grid_carrier' => ['nullable', 'string', 'max:128'],
            'grid_tracking' => ['nullable', 'string', 'max:128'],
            'grid_progress' => ['nullable', Rule::in(['unsynced', 'failed', 'synced', 'notified'])],
        ]);
        $perPage = (int) ($filters['per_page'] ?? 50);
        $sort = (string) ($filters['sort'] ?? 'date');
        $direction = (string) ($filters['direction'] ?? 'desc');
        $sortColumn = match ($sort) {
            'order_number' => 'erp_order_id',
            'source' => 'source_type',
            default => 'order_date',
        };
        $statusSnapshot = null;
        if (filled($filters['grid_progress'] ?? null)) {
            try {
                $statusSnapshot = app(OrderSyncProgressSnapshot::class)->snapshot();
            } catch (\Throwable $exception) {
                report($exception);
                // Do not silently fall back to a current-page-only filter or
                // present an incomplete remote WooCommerce result as complete.
                throw ValidationException::withMessages([
                    'grid_progress' => '全部订单状态核验暂时不可用，请稍后重试。没有修改任何订单。',
                ]);
            }
        }

        $paginator = Order::query()->withTrashed()
            ->with(['address', 'manualSync', 'shipment', 'fulfillmentParent' => fn ($query) => $query->withTrashed(), 'fulfillmentParent.externalReferences', 'externalReferences', 'operationalMarkers'])
            ->when($filters['source'] ?? null, fn ($query, $source) => $query->where('source_type', $source))
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('erp_order_id', 'like', "%{$search}%")
                        ->orWhere('external_order_number', 'like', "%{$search}%")
                        ->orWhere('internal_order_number', 'like', "%{$search}%")
                        ->orWhereHas('address', fn ($address) => $address->where('recipient_name', 'like', "%{$search}%"));
                });
            })
            ->when($filters['grid_order_number'] ?? null, fn ($query, string $term) => $query
                ->where(fn ($nested) => $nested
                    ->where('erp_order_id', 'like', "%{$term}%")
                    ->orWhere('external_order_number', 'like', "%{$term}%")
                    ->orWhere('internal_order_number', 'like', "%{$term}%")))
            ->when($filters['grid_date'] ?? null, fn ($query, string $term) => $query->where('order_date', 'like', "%{$term}%"))
            ->when($filters['grid_source'] ?? null, function ($query, string $term): void {
                // The second header row uses Chinese labels, while the DB
                // stores English source codes. Support either without SQL
                // concatenation or loading the entire order table.
                $labels = [
                    'woocommerce' => '官网 WooCommerce',
                    'website' => '网站导入',
                    'kol' => '网红订单',
                    'manual' => '手工订单',
                    'manual_other' => '其他外部导入外采',
                ];
                $matches = array_keys(array_filter($labels, fn (string $label): bool => str_contains($label, trim($term))));
                if ($matches !== []) {
                    $query->whereIn('source_type', $matches);
                } else {
                    $query->where('source_type', 'like', "%{$term}%");
                }
            })
            ->when($filters['grid_recipient'] ?? null, fn ($query, string $term) => $query
                ->whereHas('address', fn ($address) => $address->where('recipient_name', 'like', "%{$term}%")))
            ->when($filters['grid_carrier'] ?? null, fn ($query, string $term) => $query
                ->whereHas('shipment', fn ($shipment) => $shipment->where('carrier', 'like', "%{$term}%")))
            ->when($filters['grid_tracking'] ?? null, fn ($query, string $term) => $query
                ->whereHas('shipment', fn ($shipment) => $shipment->where('tracking_number', 'like', "%{$term}%")))
            ->when($statusSnapshot !== null, fn ($query) => app(OrderSyncProgressSnapshot::class)
                ->constrain($query, (string) $filters['grid_progress'], $statusSnapshot))
            ->orderBy($sortColumn, $direction)->orderBy('id', $direction)
            ->paginate($perPage)->withQueryString();

        $pageOrders = $paginator->getCollection();
        $shipmentIds = $pageOrders->pluck('shipment.id')->filter()->map(fn ($id) => (string) $id)->values();
        $trackingOutbox = SyncOutbox::query()
            ->where('operation', 'woocommerce.tracking_outbound')
            ->whereIn('aggregate_id', $shipmentIds)
            ->latest('id')
            ->get()
            ->unique('aggregate_id')
            ->keyBy('aggregate_id');
        $trackingStatuses = app(LogisticsTrackingListStatus::class)->forShipments(
            $pageOrders->pluck('shipment.id')->filter()->map(fn ($id) => (int) $id)->all()
        );
        $canOperate = (bool) $request->user()?->can('sync.operate');
        $canPush = $canOperate && app(LogisticsTrackingPushAccess::class)->allows($request->user());
        $progress = app(OrderSyncProgressProjector::class);

        $recentRuns = $this->orchestration->recentRuns(10);

        return Inertia::render('Sync/Orders', [
            // Owner-approved default rollout for Sync only; other pages keep
            // their independent GlobalGridPilot gates. Old view is always
            // accessible through the explicit ?global_grid=0 rollback link.
            'globalGridPilotAvailable' => true,
            'globalGridPilotEnabled' => $request->query('global_grid') !== '0',
            'wooOrderSync' => [
                ...app(WooOrderSyncHealthReader::class)->snapshot(),
                'can_import_today' => app(WooOrderManualSyncAccess::class)->allows($request->user()),
            ],
            'queueSummary' => $this->orchestration->queueSummary(),
            'canOperate' => $canOperate,
            'progressFilterSnapshot' => $statusSnapshot === null ? null : [
                'generated_at' => $statusSnapshot['generated_at'],
                'total' => $statusSnapshot['total'],
                'candidates' => $statusSnapshot['candidates'],
                'remote_configured' => $statusSnapshot['remote_configured'],
            ],
            'syncRuns' => $recentRuns->map(fn ($run): array => [
                'id' => $run->id,
                'run_type' => $run->run_type,
                'status' => $run->status->value,
                'requested_items' => $run->requested_items,
                'succeeded_items' => $run->succeeded_items,
                'retrying_items' => $run->retrying_items,
                'failed_items' => $run->failed_items,
                'conflict_items' => $run->conflict_items,
                'skipped_items' => $run->skipped_items,
                'started_at' => $run->started_at?->toIso8601String(),
            ])->values()->all(),
            'orders' => $pageOrders->map(function (Order $order) use ($trackingStatuses, $trackingOutbox, $canOperate, $canPush, $progress): array {
                $trackingState = app(LogisticsTrackingListStatus::class)->currentStatus(
                    $trackingStatuses[(int) $order->shipment?->id] ?? null, $order->manualSync?->website_sync_status
                );
                $root = $order->fulfillment_parent_id ? $order->fulfillmentParent : $order;
                $rootSource = $root?->source_type ?? $order->source_type;
                $rootSourceType = $rootSource instanceof \BackedEnum ? $rootSource->value : (string) $rootSource;
                $isWoo = $rootSourceType === 'woocommerce';
                $proof = $progress->forOrder($order, $trackingState, $isWoo);
                $number = trim((string) ($order->shipment?->tracking_number ?? ''));
                $isArchived = $order->trashed() || $order->archived_at !== null || $order->isOperationallyArchived()
                    || ($root?->trashed() ?? false) || $root?->archived_at !== null;
                $hasWooReference = $isWoo && ($root?->externalReferences ?? collect())->contains(
                    fn ($reference): bool => $reference->provider === 'woocommerce' && ctype_digit((string) $reference->reference)
                );
                $pushable = $canPush && ! $isArchived && $isWoo && $hasWooReference
                    && $order->shipment?->shipment_status === 'shipped'
                    && $number !== '' && ! in_array($trackingState['status'] ?? '', ['completed', 'conflict'], true);
                // A row may be shown as unsynced/failed while it is not yet
                // eligible for a website write. Make the blocker visible to
                // operators rather than offering a silently ineffective action.
                $syncBlockedReason = match (true) {
                    ! $canOperate => '当前账号没有同步操作权限。',
                    $isArchived => '订单已归档，需先恢复订单才能同步网站。',
                    ! $isWoo => '该订单未关联 WooCommerce 官网订单，不能直接同步网站。',
                    ! $hasWooReference => '缺少可信的官网 WooCommerce 母订单引用。',
                    $order->shipment?->shipment_status !== 'shipped' => '订单尚未完成发货，请先完成发货审核。',
                    $number === '' => '尚未填写物流单号，请先填写有效的 Tracking。',
                    ($trackingState['status'] ?? '') === 'conflict' => '官网物流信息存在冲突，请先核对，不能直接覆盖。',
                    ($trackingState['status'] ?? '') === 'completed' => '官网物流已核验，请刷新同步进度。',
                    ! $canPush => '当前账号或同步开关不允许执行官网物流写回。',
                    default => null,
                };

                return [
                    'id' => $order->id,
                    'erp_order_id' => $order->erp_order_id,
                    'external_order_number' => $order->external_order_number,
                    'order_date' => $order->order_date?->format('Y-m-d'),
                    'recipient_name' => $order->address?->recipient_name,
                    'source_type' => $rootSourceType,
                    'fulfillment_suffix' => $order->fulfillment_suffix,
                    'root_order_id' => $root?->id,
                    'archived' => $isArchived,
                    'tracking_number' => $order->shipment?->tracking_number,
                    'shipment_status' => $order->shipment?->shipment_status,
                    'carrier' => $order->shipment?->carrier,
                    'status' => $order->manualSync?->website_sync_status?->value ?? 'pending',
                    'synced_at' => $order->manualSync?->synced_at?->toIso8601String(),
                    'tracking_outbound_status' => $order->shipment ? $trackingOutbox->get((string) $order->shipment->id)?->status?->value : null,
                    'tracking_outbound_attempts' => $order->shipment ? $trackingOutbox->get((string) $order->shipment->id)?->attempts : null,
                    'tracking_sync_status' => $trackingState['status'],
                    'tracking_sync_reason' => $trackingState['reason'] ?? null,
                    'tracking_sync_verified_at' => $trackingState['verified_at'] ?? null,
                    'progress' => $proof,
                    'can_sync' => $pushable,
                    'sync_block_reason' => $syncBlockedReason,
                    'can_reconcile' => $canOperate && ! $isArchived && $hasWooReference && $number !== '',
                    'can_operate' => $canOperate,
                ];
            })->values()->all(),
            'pagination' => [
                'total' => $paginator->total(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => [
                'search' => $filters['search'] ?? '',
                'source' => $filters['source'] ?? '',
                'per_page' => $perPage,
                'sort' => $sort,
                'direction' => $direction,
                'grid_order_number' => $filters['grid_order_number'] ?? '',
                'grid_date' => $filters['grid_date'] ?? '',
                'grid_source' => $filters['grid_source'] ?? '',
                'grid_recipient' => $filters['grid_recipient'] ?? '',
                'grid_carrier' => $filters['grid_carrier'] ?? '',
                'grid_tracking' => $filters['grid_tracking'] ?? '',
                'grid_progress' => $filters['grid_progress'] ?? '',
            ],
        ]);
    }

    public function payload(Order $order): JsonResponse
    {
        $order->load(['address', 'items', 'shipment', 'fulfillmentParent']);
        $rootSource = $order->fulfillment_parent_id ? $order->fulfillmentParent?->source_type : $order->source_type;
        $rootSourceType = $rootSource instanceof \BackedEnum ? $rootSource->value : (string) $rootSource;

        return response()->json([
            'order_number' => $order->erp_order_id,
            'erp_order_id' => $order->erp_order_id,
            'external_order_number' => $order->external_order_number,
            'source_type' => $rootSourceType,
            'order_date' => $order->order_date?->toDateString(),
            'recipient' => $order->address?->only(['recipient_name', 'phone', 'country', 'province_state', 'city', 'address_line_1', 'address_line_2', 'postal_code']),
            'items' => $order->items->map(fn ($item): array => $item->only(['product_summary', 'sku', 'quantity', 'unit_price']))->values(),
            'carrier' => $order->shipment?->carrier,
            'tracking_number' => $order->shipment?->tracking_number,
            'shipped_at' => $order->shipment?->shipped_at?->toIso8601String(),
        ]);
    }

    public function complete(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['sync_note' => ['nullable', 'string', 'max:5000']]);
        $this->sync->complete($order, $request->user(), $data['sync_note'] ?? null);

        return back()->with('success', '人工同步已完成');
    }

    public function reopen(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['sync_note' => ['nullable', 'string', 'max:5000']]);
        $this->sync->reopen($order, $request->user(), $data['sync_note'] ?? null);

        return back()->with('success', '人工同步已重开');
    }

    public function return(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['sync_note' => ['nullable', 'string', 'max:5000']]);
        $this->sync->returnForCorrection($order, $request->user(), $data['sync_note'] ?? null);

        return back()->with('success', '人工同步已退回更正');
    }
}
