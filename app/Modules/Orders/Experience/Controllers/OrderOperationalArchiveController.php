<?php

namespace App\Modules\Orders\Experience\Controllers;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditService;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Orders\Application\KolOrderAccessScope;
use App\Modules\Orders\Application\OrderKind;
use App\Modules\Orders\Application\OrderAuxiliaryStatusService;
use App\Modules\Orders\Application\OrderWorkflowStatus;
use App\Modules\Orders\Application\OrderWorkflowStatusProjection;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'in:20,50,100'],
        ]);
        // Direct archives and children suppressed by a parent archive are both
        // listed. A child with its own archive flag can be restored separately.
        $query = Order::query()->with(['owner', 'operationalArchivedByUser', 'fulfillmentParent.operationalArchivedByUser', 'shipment.warehouse', 'operationalMarkers'])
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

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            if (preg_match('/^dbid:(\d+)$/i', $search, $match) === 1) {
                $query->whereKey((int) $match[1]);
            } else {
                // Search must happen before pagination; a record on page 2 or 3
                // must still be reachable from the first page's search field.
                $query->where(function ($matching) use ($search): void {
                    $like = '%'.addcslashes($search, '\\%_').'%';
                    $matching->where('erp_order_id', 'like', $like)
                        ->orWhere('internal_order_number', 'like', $like)
                        ->orWhere('external_order_number', 'like', $like)
                        ->orWhereHas('address', fn ($address) => $address
                            ->where('recipient_name', 'like', $like)->orWhere('email', 'like', $like))
                        ->orWhereHas('items', fn ($items) => $items->where('sku', 'like', $like))
                        ->orWhereHas('externalReferences', fn ($refs) => $refs->where('reference', 'like', $like));
                });
            }
        }

        app(\App\Modules\Orders\Application\OrderContactProjection::class)->filterRequest($query, $request);
        $orders = $query->orderByRaw('COALESCE(orders.operational_archived_at, (SELECT parent.operational_archived_at FROM orders AS parent WHERE parent.id = orders.fulfillment_parent_id)) DESC')->latest('orders.id')
            ->paginate((int) ($filters['per_page'] ?? 50))->withQueryString();

        app(\App\Modules\Orders\Application\OrderContactProjection::class)->prime($orders->getCollection());
        return Inertia::render('Orders/Archive', [
            'orders' => $orders->through(fn (Order $order): array => [
                'id' => $order->id,
                'erp_order_id' => $order->erp_order_id,
                ...app(\App\Modules\Orders\Application\OrderContactProjection::class)->for($order),
                'order_kind' => $order->order_kind?->value,
                'order_status' => $order->order_status?->value,
                'workflow_revision' => (int) $order->workflow_revision,
                'workflow_status_projection' => app(OrderWorkflowStatusProjection::class)->for($order),
                'can_change_status' => app(\App\Modules\Orders\Application\OrderWorkflowStatusPolicy::class)->for($order, $request->user())['can_edit'],
                'workflow_status_options' => app(\App\Modules\Orders\Application\OrderWorkflowStatusPolicy::class)->for($order, $request->user())['options'],
                'operational_archived_at' => ($order->operational_archived_at ?? $order->fulfillmentParent?->operational_archived_at)?->toIso8601String(),
                'archived_by' => $order->operationalArchivedByUser?->name ?? $order->fulfillmentParent?->operationalArchivedByUser?->name ?? null,
                'direct_archive' => $order->operational_archived_at !== null,
                'fulfillment_parent_id' => $order->fulfillment_parent_id,
                'owner' => $order->owner?->name,
            ])->toArray(),
            'canRestore' => (bool) $request->user()?->can('system.permissions.manage'),
            'canChangeStatus' => (bool) $request->user()?->can('orders.edit'),
            'filters' => ['search' => $search, 'per_page' => (string) ($filters['per_page'] ?? '50')],
        ]);
    }

    public function archive(Request $request, Order $order): RedirectResponse
    {
        abort_unless($request->user()?->can('orders.archive'), 403);
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

    /**
     * Thin batch adapter: each ID goes through the EXACT audited single-order
     * archive method. Individual transactions and original permissions stay intact.
     */
    public function batch(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('orders.view') && $request->user()?->can('orders.archive'), 403);
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:50'],
            'order_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);

        $results = [];
        foreach ($data['order_ids'] as $id) {
            $id = (int) $id;
            try {
                $order = Order::query()->findOrFail($id);
                $this->archive($request, $order);
                $results[] = ['order_id' => $id, 'ok' => true, 'message' => '已归档'];
            } catch (HttpExceptionInterface $exception) {
                $results[] = ['order_id' => $id, 'ok' => false,
                    'message' => $exception->getStatusCode() === 403 ? '没有权限归档该订单' : ($exception->getMessage() ?: '订单状态不允许归档')];
            } catch (ModelNotFoundException) {
                $results[] = ['order_id' => $id, 'ok' => false, 'message' => '订单不存在'];
            }
        }

        return response()->json([
            'results' => $results,
            'success_count' => count(array_filter($results, fn (array $row): bool => $row['ok'])),
            'failed_count' => count(array_filter($results, fn (array $row): bool => ! $row['ok'])),
        ]);
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
