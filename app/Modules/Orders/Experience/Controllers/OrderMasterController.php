<?php

namespace App\Modules\Orders\Experience\Controllers;

use App\Modules\Logistics\Application\WarehouseSkuPresentation;
use App\Modules\Orders\Application\KolOrderAccessScope;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderOmsAvailability;
use App\Modules\Orders\Application\OrderSourceType;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only discovery of canonical orders, including operationally archived,
 * soft-deleted, legacy-trash and derived rows. Never feeds fulfillment queues.
 */
final class OrderMasterController
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('orders.view'), 403);
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'view' => ['nullable', Rule::in(['all', 'normal', 'operational', 'trash', 'pending'])],
            'kind' => ['nullable', Rule::in(['all', ...array_column(OrderKind::cases(), 'value')])],
            'source' => ['nullable', Rule::in(['all', ...array_column(OrderSourceType::cases(), 'value')])],
            'sort' => ['nullable', Rule::in(['date', 'order_number', 'source', 'status'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', Rule::in(['20', '50', '100'])],
            'grid_order_number' => ['nullable', 'string', 'max:120'],
            'grid_external_number' => ['nullable', 'string', 'max:120'],
            'grid_recipient' => ['nullable', 'string', 'max:120'],
            'grid_sku' => ['nullable', 'string', 'max:120'],
            'grid_warehouse' => ['nullable', 'string', 'max:120'],
            'grid_status' => ['nullable', 'string', 'max:120'],
            'grid_date' => ['nullable', 'string', 'max:120'],
            'grid_source' => ['nullable', 'string', 'max:120'],
        ]);

        $filters = [
            'search' => trim((string) ($data['search'] ?? '')),
            'view' => $data['view'] ?? 'all',
            'kind' => $data['kind'] ?? 'all',
            'source' => $data['source'] ?? 'all',
            'sort' => $data['sort'] ?? 'date',
            'direction' => $data['direction'] ?? 'desc',
            'per_page' => (string) ($data['per_page'] ?? '50'),
        ];
        foreach (['order_number', 'external_number', 'recipient', 'sku', 'warehouse', 'status', 'date', 'source'] as $column) {
            $filters['grid_'.$column] = trim((string) ($data['grid_'.$column] ?? ''));
        }

        $canSeeTrash = (bool) $request->user()->can('system.permissions.manage');
        // The query is deliberately not eligibleForOperations(): that scope
        // hides the very records the Owner needs to audit.
        $base = Order::withTrashed();
        if (! $canSeeTrash) {
            // Existing trash details are already admin-only; the master list
            // must not turn their contents into a side-channel for employees.
            $base->whereNull('orders.deleted_at')->whereNull('orders.archived_at');
            $base->whereDoesntHave('fulfillmentParent', fn (Builder $parent) => $parent->withTrashed()
                ->where(fn (Builder $trash) => $trash->whereNotNull('archived_at')->orWhereNotNull('deleted_at')));
        }
        if (KolOrderAccessScope::isKolOnly($request->user())) {
            $base->where(fn (Builder $kol) => $kol
                ->where('order_kind', OrderKind::KolFulfillment->value)
                ->orWhereHas('fulfillmentParent', fn (Builder $parent) => $parent
                    ->withTrashed()->where('order_kind', OrderKind::KolFulfillment->value)));
            if (KolOrderAccessScope::isOwnerOnly($request->user())) {
                $ownerId = (int) $request->user()->getAuthIdentifier();
                $base->where(fn (Builder $owner) => $owner->where('owner_id', $ownerId)
                    ->orWhereHas('fulfillmentParent', fn (Builder $parent) => $parent
                        ->withTrashed()->where('owner_id', $ownerId)));
            }
        }
        if ($filters['kind'] !== 'all') {
            $base->where('orders.order_kind', $filters['kind']);
        }
        if ($filters['source'] !== 'all') {
            $base->where('orders.source_type', $filters['source']);
        }
        if ($filters['search'] !== '') {
            $search = $filters['search'];
            if (preg_match('/^dbid:(\d+)$/i', $search, $match) === 1) {
                $base->whereKey((int) $match[1]);
            } else {
                $like = $this->contains($search);
                $base->where(fn (Builder $match) => $match
                    ->where('erp_order_id', 'like', $like)
                    ->orWhere('internal_order_number', 'like', $like)
                    ->orWhere('external_order_number', 'like', $like)
                    ->orWhereHas('address', fn (Builder $address) => $address
                        ->where('recipient_name', 'like', $like)->orWhere('email', 'like', $like))
                    ->orWhereHas('customer', fn (Builder $customer) => $customer->where('email', 'like', $like))
                    ->orWhereHas('items', fn (Builder $items) => $items
                        ->where('sku', 'like', $like)->orWhere('source_sku', 'like', $like)
                        ->orWhere('final_sku', 'like', $like)->orWhere('product_summary', 'like', $like))
                    ->orWhereHas('externalReferences', fn (Builder $refs) => $refs->where('reference', 'like', $like))
                    ->orWhereHas('shipment', fn (Builder $shipment) => $shipment->where('tracking_number', 'like', $like)));
            }
        }
        $this->applyColumnFilters($base, $filters);

        $counts = [];
        foreach (['all', 'normal', 'operational', 'trash', 'pending'] as $view) {
            $countQuery = clone $base;
            $this->applyView($countQuery, $view);
            $counts[$view] = $countQuery->count();
        }

        $query = clone $base;
        $this->applyView($query, $filters['view']);
        $sortColumn = [
            'date' => 'orders.order_date',
            'order_number' => 'orders.erp_order_id',
            'source' => 'orders.source_type',
            'status' => 'orders.order_status',
        ][$filters['sort']];
        $orders = $query->with([
            'owner:id,name', 'address', 'items', 'shipment.warehouse', 'collaborationPartner:id,name',
            'fulfillmentParent' => fn ($parent) => $parent->withTrashed()->select('id', 'operational_archived_at', 'archived_at', 'deleted_at'),
        ])->orderBy($sortColumn, $filters['direction'])->orderBy('orders.id', 'desc')
            ->paginate((int) $filters['per_page'])->withQueryString();

        return Inertia::render('Orders/Master', [
            'orders' => $orders->through(function (Order $order): array {
                $parent = $order->fulfillmentParent;
                $isTrash = $order->deleted_at !== null || $order->archived_at !== null
                    || $parent?->deleted_at !== null || $parent?->archived_at !== null;
                $isOperational = $order->operational_archived_at !== null || $parent?->operational_archived_at !== null;

                return [
                    'id' => $order->id,
                    'number' => $order->erp_order_id ?: $order->internal_order_number ?: '#'.$order->id,
                    'external_number' => $order->external_order_number,
                    'kind' => $order->order_kind?->value,
                    'source' => $order->source_type?->value,
                    'collaboration_partner' => $order->collaborationPartner?->name,
                    'status' => $order->order_status?->value,
                    'archive_state' => $isTrash ? 'trash' : ($isOperational ? 'operational' : 'normal'),
                    'warehouse' => $order->routing_warehouse_code ?: $order->shipment?->warehouse?->name,
                    'recipient' => $order->address?->recipient_name,
                    'email' => $order->address?->email,
                    'skus' => $order->items->map(fn ($item) => WarehouseSkuPresentation::sku($item))->filter()->unique()->implode(' / '),
                    'date' => $order->order_date?->toDateString(),
                    'owner' => $order->owner?->name,
                    'detail_url' => $isTrash ? '/orders/trash' : '/orders/'.$order->id,
                ];
            })->toArray(),
            'counts' => $counts,
            'filters' => $filters,
            'canSeeTrash' => $canSeeTrash,
            'canImportOmsOrders' => $request->user()->can('orders.create')
                && ! KolOrderAccessScope::isKolOnly($request->user())
                && OrderOmsAvailability::importPreview($request->user()),
            'canConfirmOmsOrders' => $request->user()->can('orders.create')
                && ! KolOrderAccessScope::isKolOnly($request->user())
                && OrderOmsAvailability::import($request->user()),
        ]);
    }

    private function applyView(Builder $query, string $view): void
    {
        if ($view === 'trash') {
            $query->where(fn (Builder $trash) => $trash->whereNotNull('orders.deleted_at')
                ->orWhereNotNull('orders.archived_at')
                ->orWhereHas('fulfillmentParent', fn (Builder $parent) => $parent->withTrashed()
                    ->where(fn (Builder $hidden) => $hidden->whereNotNull('archived_at')->orWhereNotNull('deleted_at'))));
        } elseif ($view === 'operational') {
            $query->whereNull('orders.deleted_at')->whereNull('orders.archived_at')
                ->whereDoesntHave('fulfillmentParent', fn (Builder $parent) => $parent->withTrashed()
                    ->where(fn (Builder $trash) => $trash->whereNotNull('archived_at')->orWhereNotNull('deleted_at')))
                ->where(fn (Builder $archived) => $archived->whereNotNull('orders.operational_archived_at')
                    ->orWhereHas('fulfillmentParent', fn (Builder $parent) => $parent
                        ->withTrashed()->whereNotNull('operational_archived_at')));
        } elseif ($view === 'normal' || $view === 'pending') {
            $query->whereNull('orders.deleted_at')->whereNull('orders.archived_at')
                ->whereNull('orders.operational_archived_at')
                ->whereDoesntHave('fulfillmentParent', fn (Builder $parent) => $parent->withTrashed()
                    ->where(fn (Builder $hidden) => $hidden->whereNotNull('operational_archived_at')
                        ->orWhereNotNull('archived_at')->orWhereNotNull('deleted_at')));
            if ($view === 'pending') {
                $query->where('orders.order_status', 'pending_shipment');
            }
        }
    }

    private function applyColumnFilters(Builder $query, array $filters): void
    {
        foreach (['order_number', 'external_number', 'recipient', 'sku', 'warehouse', 'status', 'date', 'source'] as $column) {
            if ($filters['grid_'.$column] === '') {
                continue;
            }
            $like = $this->contains($filters['grid_'.$column]);
            match ($column) {
                'order_number' => $query->where('orders.erp_order_id', 'like', $like),
                'external_number' => $query->where('orders.external_order_number', 'like', $like),
                'recipient' => $query->whereHas('address', fn (Builder $address) => $address->where('recipient_name', 'like', $like)),
                'sku' => $query->whereHas('items', fn (Builder $item) => $item->where('sku', 'like', $like)
                    ->orWhere('source_sku', 'like', $like)->orWhere('final_sku', 'like', $like)),
                'warehouse' => $query->where(fn (Builder $warehouse) => $warehouse
                    ->where('orders.routing_warehouse_code', 'like', $like)
                    ->orWhereHas('shipment.warehouse', fn (Builder $actual) => $actual->where('name', 'like', $like))),
                'status' => $query->where('orders.order_status', 'like', $like),
                'date' => $query->where('orders.order_date', 'like', $like),
                'source' => $query->where('orders.source_type', 'like', $like),
            };
        }
    }

    private function contains(string $input): string
    {
        return '%'.addcslashes($input, '\\%_').'%';
    }
}
