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
            'orders' => Order::onlyTrashed()->with('owner')
                ->orderByDesc('deleted_at')->paginate(50)->through(fn (Order $order): array => [
                    'id' => $order->id,
                    'number' => $order->erp_order_id ?: $order->internal_order_number,
                    'kind' => $order->order_kind?->value,
                    'owner' => $order->owner?->name,
                    'deleted_at' => $order->deleted_at?->toDateTimeString(),
                    'deleted_by' => $order->deleted_by,
                ]),
        ]);
    }

    public function destroy(Request $request, Order $order): RedirectResponse
    {
        KolOrderAccessScope::authorize($request->user(), $order);
        DB::transaction(function () use ($request, $order): void {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            KolOrderAccessScope::authorize($request->user(), $locked);
            $this->assertSafeToRemove($locked);
            // Deletion does not cancel inventory, fulfillment, payment or commission facts.
            $locked->deleted_by = $request->user()->getAuthIdentifier();
            $locked->save();
            $locked->delete();
            $this->record($request, $locked, 'order.trashed');
        });

        return redirect()->route('orders.index')->with('success', '订单已移至回收站');
    }

    public function restore(Request $request, int $order): RedirectResponse
    {
        $this->authorizeAdmin($request);
        DB::transaction(function () use ($request, $order): void {
            $locked = Order::onlyTrashed()->whereKey($order)->lockForUpdate()->firstOrFail();
            $locked->restore();
            $this->record($request, $locked, 'order.restored');
        });

        return back()->with('success', '订单已恢复');
    }

    public function purge(Request $request, int $order): RedirectResponse
    {
        $this->authorizeAdmin($request);
        DB::transaction(function () use ($request, $order): void {
            $locked = Order::onlyTrashed()->whereKey($order)->lockForUpdate()->firstOrFail();
            // The same safety gate applies even to legacy trashed orders.
            $this->assertSafeToRemove($locked);
            $this->record($request, $locked, 'order.purged');
            $locked->forceDelete();
        });

        return back()->with('success', '订单已永久删除');
    }

    private function assertSafeToRemove(Order $order): void
    {
        $status = $order->order_status instanceof \BackedEnum
            ? $order->order_status->value
            : (string) $order->order_status;
        abort_unless(in_array($status, ['pending_shipment', 'cancelled'], true), 409,
            '订单已进入业务履约状态，禁止直接移除。请先完成业务冲销。');

        foreach (self::PROTECTED_RELATIONS as $relation) {
            abort_if($order->{$relation}()->exists(), 409,
                '订单存在物流、财务或外部同步记录，禁止直接移除，请先完成业务处理。');
        }

        // Eloquent SoftDeletes would hide a trashed parent/child from relation queries.
        abort_if($order->fulfillment_parent_id !== null || $order->fulfillment_origin_id !== null, 409,
            '拆分或补发订单存在父子履约关系，不能移除。');
        abort_if(DB::table('orders')->where('fulfillment_parent_id', $order->id)
            ->orWhere('fulfillment_origin_id', $order->id)->exists(), 409,
            '订单存在拆分或补发履约记录，不能移除。');

        foreach (self::PROTECTED_TABLES as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
                abort_if(DB::table($table)->where($column, $order->id)->exists(), 409,
                    '订单存在库存预留、历史成本或网红佣金关联，禁止移除。');
            }
        }
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
            before: ['erp_order_id' => $order->erp_order_id, 'deleted_at' => $order->deleted_at?->toIso8601String()],
            after: ['actor_id' => $request->user()->getAuthIdentifier()],
            reason: 'order_trash_v1',
        );
    }
}
