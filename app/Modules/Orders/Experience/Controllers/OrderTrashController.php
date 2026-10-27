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
use Inertia\Inertia;
use Inertia\Response;

final class OrderTrashController
{
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
            // Soft deletion never cancels inventory, fulfillment, payments, or commissions.
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
            // Never cascade-delete records with operational, financial, stock, or external history.
            $protectedRelations = [
                'shipment', 'shipmentAttempts', 'fulfillmentAllocation', 'financials',
                'costEntries', 'manualSync', 'externalReferences', 'procurementOrder',
                'logisticsReview', 'fulfillmentChildren', 'fulfillmentParent',
                'operationalMarkers',
            ];
            foreach ($protectedRelations as $relation) {
                if ($locked->{$relation}()->exists()) {
                    abort(409, '订单存在关联业务记录，禁止永久删除。请保留在回收站。');
                }
            }
            $this->record($request, $locked, 'order.purged');
            $locked->forceDelete();
        });

        return back()->with('success', '订单已永久删除');
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
