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
        // Direct archives and children suppressed by a parent archive are both
        // listed. A child with its own archive flag can be restored separately.
        $query = Order::query()->with(['owner', 'operationalArchivedByUser', 'fulfillmentParent.operationalArchivedByUser'])
            ->whereNull('archived_at')
            ->where(function ($archived): void {
                $archived->whereNotNull('operational_archived_at')
                    ->orWhereHas('fulfillmentParent', fn ($parent) => $parent->whereNotNull('operational_archived_at'));
            });
        if (KolOrderAccessScope::isKolOnly($request->user())) {
            $query->where(function ($kol): void {
                $kol->where('order_kind', OrderKind::KolFulfillment->value)
                    ->orWhereHas('fulfillmentParent', fn ($parent) => $parent->where('order_kind', OrderKind::KolFulfillment->value));
            });
            if (KolOrderAccessScope::isOwnerOnly($request->user())) {
                $ownerId = $request->user()->getAuthIdentifier();
                $query->where(fn ($owned) => $owned->where('owner_id', $ownerId)
                    ->orWhereHas('fulfillmentParent', fn ($parent) => $parent->where('owner_id', $ownerId)));
            }
        }

        $orders = $query->orderByRaw('COALESCE(orders.operational_archived_at, (SELECT parent.operational_archived_at FROM orders AS parent WHERE parent.id = orders.fulfillment_parent_id)) DESC')->latest('orders.id')->paginate(50)->withQueryString();

        return Inertia::render('Orders/Archive', [
            'orders' => $orders->through(fn (Order $order): array => [
                'id' => $order->id,
                'erp_order_id' => $order->erp_order_id,
                'order_kind' => $order->order_kind?->value,
                'order_status' => $order->order_status?->value,
                'operational_archived_at' => ($order->operational_archived_at ?? $order->fulfillmentParent?->operational_archived_at)?->toIso8601String(),
                'archived_by' => $order->operationalArchivedByUser?->name ?? $order->fulfillmentParent?->operationalArchivedByUser?->name ?? null,
                'direct_archive' => $order->operational_archived_at !== null,
                'fulfillment_parent_id' => $order->fulfillment_parent_id,
                'owner' => $order->owner?->name,
            ])->toArray(),
            'canRestore' => (bool) $request->user()?->can('system.permissions.manage'),
        ]);
    }

    public function archive(Request $request, Order $order): RedirectResponse
    {
        abort_unless($request->user()?->can('orders.edit'), 403);
        KolOrderAccessScope::authorize($request->user(), $order->fulfillment_parent_id ? $order->fulfillmentParent()->firstOrFail() : $order);
        DB::transaction(function () use ($request, $order): void {
            $locked = Order::query()->with('fulfillmentParent')->whereKey($order->id)->lockForUpdate()->firstOrFail();
            KolOrderAccessScope::authorize($request->user(), $locked->fulfillmentParent ?? $locked);
            abort_if($locked->trashed() || $locked->archived_at !== null, 409, '回收站订单不能进行运营归档。');
            abort_if($locked->operational_archived_at !== null, 409, '该订单已经单独归档。');
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
            KolOrderAccessScope::authorize($request->user(), $locked->fulfillmentParent ?? $locked);
            abort_if($locked->archived_at !== null || $locked->trashed(), 409, '回收站订单不能恢复运营。');
            abort_if($locked->operational_archived_at === null, 409, '该订单没有独立归档记录，请恢复其主订单。');
            $before = ['operational_archived_at' => $locked->operational_archived_at?->toIso8601String(), 'operational_archived_by' => $locked->operational_archived_by];
            $locked->forceFill(['operational_archived_at' => null, 'operational_archived_by' => null])->save();
            $this->audit->record(
                AuditActor::user($request->user(), AuditSource::Web),
                'order.operational_archive_restored', Order::class, $locked->id, $before,
                ['operational_archived_at' => null, 'operational_archived_by' => null],
                'order_operational_archive_v1',
            );
        });

        return back()->with('success', '订单已恢复运营资格');
    }
}
