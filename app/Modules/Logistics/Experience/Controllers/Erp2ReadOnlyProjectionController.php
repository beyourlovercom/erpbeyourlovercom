<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\DomesticPendingQueue;
use App\Modules\Logistics\Application\LogisticsStatusSummary;
use App\Modules\Logistics\Application\OverseaWarehouseReviewService;
use App\Modules\Logistics\Application\OverseasShipmentQueue;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class Erp2ReadOnlyProjectionController
{
    private const WAREHOUSES = ['FACTORY', 'OUTSOURCE', 'US-NY', 'US-LA', 'US-SM', 'US-AMZ', 'DE', 'CA'];
    private const SORTS = ['order_date', 'order_number', 'status'];
    private const OVERSEAS_VIEWS = ['review', 'stockout', 'pending', 'pending_dispatch', 'issued', 'dispatched', 'shipped', 'exceptions', 'all'];

    public function pendingAllocation(Request $request, LogisticsStatusSummary $status): JsonResponse
    {
        $query = $request->query();
        $unknown = array_diff(array_keys($query), ['view', 'warehouse', 'page', 'per_page', 'search', 'sort', 'direction']);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['query' => 'Unsupported query parameter.']);
        }
        if (isset($query['view']) && $query['view'] !== 'pending') {
            throw ValidationException::withMessages(['view' => 'Unsupported allocation view.']);
        }
        if (isset($query['warehouse']) && ! in_array(strtoupper((string) $query['warehouse']), self::WAREHOUSES, true)) {
            throw ValidationException::withMessages(['warehouse' => 'Unsupported warehouse.']);
        }
        if (isset($query['sort']) && ! in_array($query['sort'], self::SORTS, true)) {
            throw ValidationException::withMessages(['sort' => 'Unsupported sort key.']);
        }
        if (isset($query['direction']) && ! in_array(strtolower((string) $query['direction']), ['asc', 'desc'], true)) {
            throw ValidationException::withMessages(['direction' => 'Unsupported sort direction.']);
        }
        $validated = validator($query, [
            'view' => ['sometimes', 'string'], 'warehouse' => ['sometimes', 'string'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'in:20,50,100'],
            'search' => ['sometimes', 'string', 'max:100'], 'sort' => ['sometimes', 'string'],
            'direction' => ['sometimes', 'string'],
        ])->validate();

        $builder = $status->pendingAllocationQuery()->with(['shipment.warehouse', 'items']);
        if (! empty($validated['warehouse'])) {
            $warehouse = strtoupper($validated['warehouse']);
            $builder->where(fn (Builder $orders) => $orders->where('routing_warehouse_code', $warehouse)
                ->orWhereHas('shipment.warehouse', fn (Builder $w) => $w->where('code', $warehouse)));
        }
        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $pattern = '%'.addcslashes($search, '%_\\').'%';
            $builder->where(fn (Builder $orders) => $orders
                ->where('erp_order_id', 'like', $pattern)
                ->orWhere('external_order_number', 'like', $pattern)
                ->orWhereHas('items', fn (Builder $items) => $items
                    ->where('sku', 'like', $pattern)->orWhere('source_sku', 'like', $pattern)
                    ->orWhere('final_sku', 'like', $pattern)));
        }

        return $this->jsonPage($builder, $validated);
    }

    public function pendingDomestic(Request $request, DomesticPendingQueue $queue): JsonResponse
    {
        $query = $request->query();
        $unknown = array_diff(array_keys($query), ['view', 'warehouse', 'page', 'per_page', 'search', 'sort', 'direction']);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['query' => 'Unsupported query parameter.']);
        }
        if (isset($query['view']) && $query['view'] !== 'pending') {
            throw ValidationException::withMessages(['view' => 'Unsupported domestic view.']);
        }
        if (isset($query['warehouse']) && ! in_array(strtoupper((string) $query['warehouse']), self::WAREHOUSES, true)) {
            throw ValidationException::withMessages(['warehouse' => 'Unsupported warehouse.']);
        }
        if (isset($query['sort']) && ! in_array($query['sort'], self::SORTS, true)) {
            throw ValidationException::withMessages(['sort' => 'Unsupported sort key.']);
        }
        if (isset($query['direction']) && ! in_array(strtolower((string) $query['direction']), ['asc', 'desc'], true)) {
            throw ValidationException::withMessages(['direction' => 'Unsupported sort direction.']);
        }
        $validated = validator($query, [
            'view' => ['sometimes', 'string'],
            'warehouse' => ['sometimes', 'string'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'in:20,50,100'],
            'search' => ['sometimes', 'string', 'max:100'],
            'sort' => ['sometimes', 'string'],
            'direction' => ['sometimes', 'string'],
        ])->validate();

        $factory = Warehouse::query()->whereRaw('UPPER(code) = ?', ['FACTORY'])->where('is_active', true)->firstOrFail();
        $builder = $queue->pending($queue->base((int) $factory->id))
            ->with(['shipment.warehouse', 'items']);
        if (! empty($validated['warehouse'])) {
            $warehouse = strtoupper($validated['warehouse']);
            $builder->where(fn (Builder $orders) => $orders->where('routing_warehouse_code', $warehouse)
                ->orWhereHas('shipment.warehouse', fn (Builder $w) => $w->where('code', $warehouse)));
        }
        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $pattern = '%'.addcslashes($search, '%_\\').'%';
            $builder->where(fn (Builder $orders) => $orders
                ->where('erp_order_id', 'like', $pattern)
                ->orWhere('external_order_number', 'like', $pattern)
                ->orWhereHas('items', fn (Builder $items) => $items
                    ->where('sku', 'like', $pattern)
                    ->orWhere('source_sku', 'like', $pattern)
                    ->orWhere('final_sku', 'like', $pattern)));
        }
        return $this->jsonPage($builder, $validated);
    }

    public function overseas(Request $request, OverseasShipmentQueue $queue): JsonResponse
    {
        $query = $request->query();
        $allowed = ['view', 'warehouse', 'page', 'per_page', 'search', 'sort', 'direction'];
        if (array_diff(array_keys($query), $allowed) !== []) {
            throw ValidationException::withMessages(['query' => 'Unsupported query parameter.']);
        }
        if (isset($query['view']) && ! in_array($query['view'], self::OVERSEAS_VIEWS, true)) {
            throw ValidationException::withMessages(['view' => 'Unsupported overseas view.']);
        }
        $warehouseAliases = ['us-sm' => 'us-3sm', 'us-sj' => 'us-3sm', 'usa-sj' => 'us-3sm'];
        $warehouseValue = strtolower(trim((string) ($query['warehouse'] ?? 'all')));
        $warehouseValue = $warehouseAliases[$warehouseValue] ?? $warehouseValue;
        $warehouseByCode = array_change_key_case(array_flip(OverseaWarehouseReviewService::WAREHOUSE_CODE_BY_KEY), CASE_LOWER);
        $warehouseKey = $warehouseByCode[$warehouseValue] ?? $warehouseValue;
        $validWarehouses = ['all', ...array_keys(OverseaWarehouseReviewService::WAREHOUSE_CODE_BY_KEY), ...array_map('strtolower', array_values(OverseaWarehouseReviewService::WAREHOUSE_CODE_BY_KEY))];
        if (isset($query['warehouse']) && ! in_array($warehouseValue, $validWarehouses, true)) {
            throw ValidationException::withMessages(['warehouse' => 'Unsupported warehouse.']);
        }
        if (isset($query['sort']) && ! in_array($query['sort'], ['line', 'shipped_at', 'tracking'], true)) {
            throw ValidationException::withMessages(['sort' => 'Unsupported sort key.']);
        }
        if (isset($query['direction']) && ! in_array(strtolower((string) $query['direction']), ['asc', 'desc'], true)) {
            throw ValidationException::withMessages(['direction' => 'Unsupported sort direction.']);
        }
        $validated = validator($query, [
            'view' => ['sometimes', 'string'], 'warehouse' => ['sometimes', 'string'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'in:20,50,100'],
            'search' => ['sometimes', 'string', 'max:100'], 'sort' => ['sometimes', 'string'],
            'direction' => ['sometimes', 'string'],
        ])->validate();

        $view = match ($validated['view'] ?? 'review') {
            'pending_dispatch' => 'pending', 'dispatched' => 'issued', default => $validated['view'] ?? 'review',
        };
        $warehouseMap = $queue->warehouseMap();
        $selected = null;
        if ($warehouseKey !== 'all') {
            $selected = $warehouseMap[$warehouseKey] ?? null;
            abort_unless($selected, 404, '仓库尚未建立');
        }
        $builder = $queue->baseQuery($warehouseMap, $selected);
        $queue->forView($builder, $view);
        $builder->with(['order.address', 'order.items', 'order.fulfillmentParent', 'warehouse', 'overseaReview']);
        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $builder->where(fn (Builder $q) => $q->where('tracking_number', 'like', $like)
                ->orWhereHas('order', fn (Builder $orders) => $orders->where('erp_order_id', 'like', $like))
                ->orWhereHas('order.address', fn (Builder $addresses) => $addresses->where('recipient_name', 'like', $like))
                ->orWhereHas('order.items', fn (Builder $items) => $items->where('sku', 'like', $like)
                    ->orWhere('source_sku', 'like', $like)->orWhere('final_sku', 'like', $like)));
        }
        $sort = $validated['sort'] ?? 'line';
        $sortColumn = ['line' => 'id', 'shipped_at' => 'shipped_at', 'tracking' => 'tracking_number'][$sort];
        $direction = strtolower($validated['direction'] ?? 'desc');
        $page = $builder->orderBy($sortColumn, $direction)->orderBy('id', 'desc')
            ->paginate((int) ($validated['per_page'] ?? 50), ['*'], 'page', (int) ($validated['page'] ?? 1));
        $omsAttempts = XlwmsDispatchAttempt::query()->whereIn('order_id', collect($page->items())->pluck('order_id')->all())
            ->get()->keyBy('order_id');
        $data = collect($page->items())->map(fn (Shipment $shipment): array => [
            'shipment_id' => (int) $shipment->id,
            'order_id' => (int) $shipment->order_id,
            'order_number' => $shipment->order?->erp_order_id,
            'order_date' => $shipment->order?->order_date?->toDateString(),
            'workflow_status' => $shipment->order?->workflow_status,
            'shipment_status' => $shipment->shipment_status,
            'warehouse_code' => $shipment->warehouse?->code,
            'carrier' => $shipment->carrier ?: OverseaWarehouseReviewService::carrierForWarehouseCode($shipment->warehouse?->code),
            'tracking' => $shipment->tracking_number,
            'shipped_at' => $shipment->shipped_at?->toIso8601String(),
            'review_status' => $shipment->overseaReview?->status === 'approved'
                && (int) $shipment->overseaReview->reviewed_warehouse_id === (int) $shipment->warehouse_id ? 'approved'
                : ($shipment->overseaReview?->status === 'stockout'
                    && (int) $shipment->overseaReview->reviewed_warehouse_id === (int) $shipment->warehouse_id ? 'stockout' : 'pending'),
            'review_state' => $shipment->overseaReview?->status,
            'oms' => $omsAttempts->get($shipment->order_id) ? [
                'state' => $omsAttempts->get($shipment->order_id)->state,
                'order_number' => $omsAttempts->get($shipment->order_id)->oms_order_no,
                'status_code' => $omsAttempts->get($shipment->order_id)->oms_status_code,
                'status_checked_at' => $omsAttempts->get($shipment->order_id)->oms_status_checked_at,
                'tracking' => $omsAttempts->get($shipment->order_id)->oms_tracking_no,
            ] : null,
            'reviewed_warehouse_id' => $shipment->overseaReview?->reviewed_warehouse_id,
            'fulfillment_parent_id' => $shipment->order?->fulfillment_parent_id,
            'fulfillment_suffix' => $shipment->order?->fulfillment_suffix,
            'items' => $shipment->order?->items->map(fn ($item): array => [
                'sku' => $item->sku, 'original_sku' => $item->sku,
                'resolved_sku' => $item->resolved_fulfillment_sku,
                'final_sku' => $item->final_sku, 'source_sku' => $item->source_sku ?: $item->sku,
                'quantity' => (int) $item->quantity,
            ])->values()->all() ?? [],
        ])->values()->all();

        return response()->json(['data' => $data, 'meta' => [
            'current_page' => $page->currentPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'last_page' => $page->lastPage(),
        ]])->header('Cache-Control', 'private, no-store');
    }

    private function jsonPage(Builder $builder, array $validated): JsonResponse
    {
        $sort = $validated['sort'] ?? 'order_date';
        $direction = strtolower($validated['direction'] ?? 'desc');
        $sortColumn = match ($sort) {
            'order_date' => 'order_date',
            'order_number' => 'erp_order_id',
            'status' => 'workflow_status',
        };
        $page = $builder->orderBy($sortColumn, $direction)->orderBy('id', 'desc')
            ->paginate((int) ($validated['per_page'] ?? 50), ['*'], 'page', (int) ($validated['page'] ?? 1));
        $items = collect($page->items())->map(fn (Order $order): array => [
            'id' => (int) $order->id,
            'order_number' => $order->erp_order_id,
            'order_date' => $order->order_date?->toDateString(),
            'status' => $order->workflow_status,
            'warehouse_code' => $order->routing_warehouse_code ?: $order->shipment?->warehouse?->code,
            'tracking' => $order->shipment?->tracking_number,
            'carrier' => $order->shipment?->carrier,
            'items' => $order->items->map(fn ($item): array => [
                'sku' => $item->sku,
                'resolved_sku' => $item->resolved_fulfillment_sku,
                'final_sku' => $item->final_sku,
                'quantity' => (int) $item->quantity,
            ])->values()->all(),
        ])->values()->all();

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage(),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }
}
