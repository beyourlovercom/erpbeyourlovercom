<?php

namespace App\Modules\Orders\Experience\Controllers;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditService;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Orders\Application\KolOrderAccessScope;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class OrderOperationalArchiveController
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('orders.view'), 403);
        // The root controls archive/restore; derived fulfillments inherit the
        // lockout but are not duplicate rows in the archive list.
        $query = Order::query()->with(['owner', 'operationalArchivedByUser'])
            ->whereNull('fulfillment_parent_id')
            ->whereNotNull('operational_archived_at')
            ->whereNull('archived_at');
        if (KolOrderAccessScope::isKolOnly($request->user())) {
            $query->where('order_kind', OrderKind::KolFulfillment->value);
            if (KolOrderAccessScope::isOwnerOnly($request->user())) {
                $query->where('owner_id', $request->user()->getAuthIdentifier());
            }
        }

        $orders = $query->latest('operational_archived_at')->latest('id')->paginate(50)->withQueryString();

        return Inertia::render('Orders/Archive', [
            'orders' => $orders->through(fn (Order $order): array => [
                'id' => $order->id,
                'erp_order_id' => $order->erp_order_id,
                'order_kind' => $order->order_kind?->value,
                'order_status' => $order->order_status?->value,
                'operational_archived_at' => $order->operational_archived_at?->toIso8601String(),
                'archived_by' => $order->operationalArchivedByUser?->name ?? ($order->operational_archived_by ? '#'.$order->operational_archived_by : null),
                'owner' => $order->owner?->name,
            ])->toArray(),
            'canRestore' => (bool) $request->user()?->can('system.permissions.manage'),
        ]);
    }

    public function archive(Request $request, Order $order): RedirectResponse
    {
        abort_unless($request->user()?->can('orders.edit'), 403);
        KolOrderAccessScope::authorize($request->user(), $order);
        DB::transaction(function () use ($request, $order): void {
            $locked = Order::query()->with('fulfillmentParent')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            KolOrderAccessScope::authorize($request->user(), $locked);
            abort_if($locked->order_kind === OrderKind::FulfillmentDerived, 409, '请归档原订单，不能直接归档履约子单。');
            abort_if($locked->trashed() || $locked->archived_at !== null, 409, '回收站订单不能进行运营归档。');
            abort_if($locked->isOperationallyArchived(), 409, '订单已归档。');
            $before = ['operational_archived_at' => null, 'operational_archived_by' => null, 'archived_at' => $locked->archived_at?->toIso8601String()];
            $locked->forceFill([
                'operational_archived_at' => now(),
                'operational_archived_by' => $request->user()->getAuthIdentifier(),
            ])->save();
            $this->audit->record(
                AuditActor::user($request->user(), AuditSource::Web),
                'order.operational_archived', Order::class, $locked->id, $before,
                ['operational_archived_at' => $locked->operational_archived_at?->toIso8601String(), 'operational_archived_by' => $locked->operational_archived_by],
                'order_operational_archive_v1',
            );
        });

        return redirect()->route('orders.archive.index')->with('success', '订单已归档至运营归档清单');
    }

    public function restore(Request $request, int $order): RedirectResponse
    {
        abort_unless($request->user()?->can('system.permissions.manage'), 403);
        DB::transaction(function () use ($request, $order): void {
            $locked = Order::query()->with('fulfillmentParent')->whereKey($order)->lockForUpdate()->firstOrFail();
            KolOrderAccessScope::authorize($request->user(), $locked);
            $rootId = $locked->fulfillment_parent_id ?: $locked->id;
            $root = Order::query()->whereKey($rootId)->lockForUpdate()->firstOrFail();
            abort_if($root->archived_at !== null || $root->trashed(), 409, '回收站订单不能恢复运营。');
            abort_if($root->operational_archived_at === null, 409, '订单不在运营归档清单。');
            $before = ['operational_archived_at' => $root->operational_archived_at?->toIso8601String(), 'operational_archived_by' => $root->operational_archived_by];
            $root->forceFill(['operational_archived_at' => null, 'operational_archived_by' => null])->save();
            $this->audit->record(
                AuditActor::user($request->user(), AuditSource::Web),
                'order.operational_archive_restored', Order::class, $root->id, $before,
                ['operational_archived_at' => null, 'operational_archived_by' => null],
                'order_operational_archive_v1',
            );
        });

        return back()->with('success', '订单已恢复运营资格');
    }
}
