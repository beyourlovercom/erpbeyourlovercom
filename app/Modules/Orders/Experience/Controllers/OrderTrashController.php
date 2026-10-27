<?php

namespace App\Modules\Orders\Experience\Controllers;

use App\Modules\Audit\Application\AuditService;
use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Orders\Application\KolOrderAccessScope;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

final class OrderTrashController
{
    /**
     * Financial, logistic and synchronization references must remain operational
     * and discoverable; a trashed order cannot become an orphaned shipment or
     * silently disappear from a settlement or reservation workflow.
     */
    private const PROTECTED_RELATIONS = [
        'shipment', 'shipmentAttempts', 'fulfillmentAllocation', 'financials',
        'costEntries', 'manualSync', 'externalReferences', 'procurementOrder',
        'logisticsReview', 'operationalMarkers',
    ];

    /** @var array<string, string> Non-Eloquent references to canonical orders. */
    private const PROTECTED_TABLES = [
        'inventory_reservations' => 'order_id',
        'warehouse_inventory_lot_consumptions' => 'order_id',
        'warehouse_storage_cost_import_rows' => 'order_id',
        'kol_affiliate_referrals' => 'order_id',
        'kol_collaborations' => 'order_id',
        'logistics_review_histories' => 'order_id',
    ];

    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): Response
    {
        $this->authorizeAdmin($request);
        return Inertia::render('Orders/Trash', [
            'orders' => Order::withTrashed()->where(fn ($q) => $q->whereNotNull('deleted_at')->orWhereNotNull('archived_at'))
                ->with('owner')->orderByRaw('COALESCE(archived_at, deleted_at) DESC')
                ->paginate(50)->through(fn (Order $order): array => [
                    'id' => $order->id,
                    'number' => $order->erp_order_id ?: $order->internal_order_number,
                    'kind' => $order->order_kind?->value,
                    'owner' => $order->owner?->name,
                    'deleted_at' => ($order->archived_at ?? $order->deleted_at)?->toDateTimeString(),
                    'deleted_by' => $order->archived_by ?? $order->deleted_by,
                    'archival_mode' => $order->archived_at !== null ? 'preserved' : 'soft_deleted',
                ]),
        ]);
    }

    public function destroy(Request $request, Order $order): RedirectResponse
    {
        KolOrderAccessScope::authorize($request->user(), $order);
        DB::transaction(function () use ($request, $order): void {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            KolOrderAccessScope::authorize($request->user(), $locked);
            abort_if($locked->archived_at !== null, 409, '订单已在回收站。');
            // Business evidence remains queryable for fulfillment, finance, stock and Woo sync.
            if ($this->hasBusinessEvidence($locked)) {
                $locked->archived_at = now();
                $locked->archived_by = $request->user()->getAuthIdentifier();
                $locked->save();
                $this->record($request, $locked, 'order.archived_with_evidence');
            } else {
                $locked->deleted_by = $request->user()->getAuthIdentifier();
                $locked->save();
                $locked->delete();
                $this->record($request, $locked, 'order.trashed');
            }
        });

        return redirect()->route('orders.index')->with('success', '订单已移至回收站');
    }

    public function restore(Request $request, int $order): RedirectResponse
    {
        $this->authorizeAdmin($request);
        DB::transaction(function () use ($request, $order): void {
            $locked = Order::withTrashed()
                ->where(fn ($q) => $q->whereNotNull('deleted_at')->orWhereNotNull('archived_at'))
                ->whereKey($order)->lockForUpdate()->firstOrFail();
            if ($locked->archived_at !== null) {
                $locked->archived_at = null;
                $locked->archived_by = null;
                $locked->save();
            }
            if ($locked->trashed()) {
                $locked->restore();
            }
            $this->record($request, $locked, 'order.restored');
        });

        return back()->with('success', '订单已恢复');
    }

    public function purge(Request $request, int $order): RedirectResponse
    {
        $this->authorizeAdmin($request);
        DB::transaction(function () use ($request, $order): void {
            $locked = Order::withTrashed()->where(fn ($q) => $q->whereNotNull('deleted_at')->orWhereNotNull('archived_at'))
                ->whereKey($order)->lockForUpdate()->firstOrFail();
            abort_if($locked->archived_at !== null, 409, '保留业务证据的归档订单不能永久删除。');
            // Protected business evidence can never be physically deleted.
            abort_if($this->hasBusinessEvidence($locked), 409,
                '订单存在业务关联，不能永久删除。请保留在回收站。');
            $this->record($request, $locked, 'order.purged');
            $locked->forceDelete();
        });

        return back()->with('success', '订单已永久删除');
    }

    private function hasBusinessEvidence(Order $order): bool
    {
        $status = $order->order_status instanceof \BackedEnum
            ? $order->order_status->value
            : (string) $order->order_status;
        if (! in_array($status, ['pending_shipment', 'cancelled'], true)) {
            return true;
        }
        // Never make an imported/Woo order invisible to inbound reconciliation.
        $source = $order->source_type instanceof \BackedEnum ? $order->source_type->value : (string) $order->source_type;
        if ($source !== 'manual') {
            return true;
        }
        foreach (self::PROTECTED_RELATIONS as $relation) {
            if ($order->{$relation}()->exists()) {
                return true;
            }
        }
        if ($order->fulfillment_parent_id !== null || $order->fulfillment_origin_id !== null) {
            return true;
        }
        if (DB::table('orders')->where('fulfillment_parent_id', $order->id)
            ->orWhere('fulfillment_origin_id', $order->id)->exists()) {
            return true;
        }
        foreach (self::PROTECTED_TABLES as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)
                && DB::table($table)->where($column, $order->id)->exists()) {
                return true;
            }
        }
        return false;
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->can('system.permissions.manage'), 403);
    }

    private function record(Request $request, Order $order, string $action): void
    {
        $this->audit->record(
            AuditActor::user($request->user(), AuditSource::Web),
            $action,
            Order::class,
            $order->id,
            before: ['erp_order_id' => $order->erp_order_id, 'deleted_at' => $order->deleted_at?->toIso8601String(), 'archived_at' => $order->archived_at?->toIso8601String()],
            after: ['actor_id' => $request->user()->getAuthIdentifier()],
            reason: 'order_trash_v1',
        );
    }
}
