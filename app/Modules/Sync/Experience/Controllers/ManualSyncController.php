<?php

namespace App\Modules\Sync\Experience\Controllers;

use App\Modules\Logistics\Application\LogisticsTrackingListStatus;
use App\Modules\Logistics\Application\LogisticsTrackingPushAccess;
use App\Modules\Orders\Application\Inbound\WooOrderManualSyncAccess;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\ManualSyncService;
use App\Modules\Sync\Application\OrderSyncProgressProjector;
use App\Modules\Sync\Application\SyncOrchestrationService;
use App\Modules\Sync\Application\WooOrderSyncHealthReader;
use App\Modules\Sync\Infrastructure\Models\SyncOutbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        ]);
        $perPage = (int) ($filters['per_page'] ?? 50);
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
            ->orderByDesc('order_date')->orderByDesc('id')
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
            'wooOrderSync' => [
                ...app(WooOrderSyncHealthReader::class)->snapshot(),
                'can_import_today' => app(WooOrderManualSyncAccess::class)->allows($request->user()),
            ],
            'queueSummary' => $this->orchestration->queueSummary(),
            'canOperate' => $canOperate,
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
