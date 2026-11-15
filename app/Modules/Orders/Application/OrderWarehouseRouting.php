<?php

namespace App\Modules\Orders\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class OrderWarehouseRouting
{
    public const UNASSIGNED = 'UNASSIGNED';

    public const OUTSOURCE = 'OUTSOURCE';

    public function __construct(private readonly AuditRecorder $audit) {}

    /** Same active choices as Vita's manual allocation, without performing allocation. */
    public function options(): array
    {
        $codes = (array) config('warehouse_master.fulfillment_codes', []);
        $known = Warehouse::query()->where('is_active', true)->whereIn('code', $codes)
            ->get(['code', 'name'])->keyBy('code');

        $rows = [['code' => self::UNASSIGNED, 'name' => '未分仓']];
        foreach ($codes as $code) {
            if ($warehouse = $known->get($code)) {
                $rows[] = ['code' => $code, 'name' => $warehouse->name];
            }
        }
        $rows[] = ['code' => self::OUTSOURCE, 'name' => '外采 → 中国工厂发货'];

        return $rows;
    }

    public function project(Order $order): array
    {
        $code = $order->routing_warehouse_code;
        $manual = $code !== null;
        if (! $manual) {
            $order->loadMissing('shipment.warehouse');
            $code = $order->shipment?->warehouse?->code;
            if ($code === null && $order->fulfillment_parent_id !== null) {
                $order->loadMissing('fulfillmentParent');
                $code = $order->fulfillmentParent?->routing_warehouse_code;
            }
        }
        $names = collect((array) config('warehouse_master.warehouses'))
            ->mapWithKeys(fn (array $item, string $key) => [$key => $item['name_zh'] ?? $key]);
        $name = match ($code) {
            self::OUTSOURCE => '外采 → 中国工厂发货',
            self::UNASSIGNED, null => '未分仓',
            default => $names->get($code, $code),
        };

        return [
            'code' => $code ?? self::UNASSIGNED,
            'name' => $name,
            'source' => $manual ? 'manual' : 'physical_fallback',
            'revision' => (int) $order->routing_revision,
        ];
    }

    /**
     * Exact post-save destination: a warehouse AND the order's effective
     * phase, never a stale list from which the change was initiated.
     * Route-only drafts remain non-dispatchable until actual fulfillment review.
     */
    public function destination(Order $order, ?Authenticatable $actor = null): string
    {
        $route = $this->project($order)['code'];
        $phase = app(OrderProgressResolver::class)->resolve($order)
            ?? ($order->workflow_status ?: 'sending');

        $view = match ($phase) {
            'pending_allocation', 'allocated' => 'pending_allocation',
            'out_of_stock' => 'stockout',
            'exception', 'missing_info', 'progress_conflict',
            'claims', 'returned_follow_up', 'renew',
            'freight_product_cost_follow_up' => 'exceptions',
            'pending_review' => 'review',
            'sending' => 'pending',
            'sent', 'shipping', 'partially_shipped', 'completed' => 'shipped',
            'cancelled', 'archived' => 'cancelled',
            default => 'pending',
        };
        if ($view === 'pending_allocation') {
            return '/logistics/wait-stock?global_grid=1';
        }
        if ($view === 'cancelled') {
            return '/orders?view=cancelled';
        }
        if ($route === self::UNASSIGNED) {
            return '/orders?view='.($view === 'exceptions' ? 'exception' : $phase);
        }

        // Staff-only permissions are still enforced by destination controllers.
        // A restricted actor uses the common Orders list they may already read.
        if ($actor && ! $actor->can('logistics.view')) {
            return '/orders?view='.($view === 'exceptions' ? 'exception' : $phase);
        }

        $overseas = [
            'US-NY' => 'us-ny',
            'US-LA' => 'us-moreplus',
            'US-SM' => 'us-3sm',
            'DE' => 'de-tianyun',
            'CA' => 'ca',
        ];
        if (isset($overseas[$route])) {
            $queue = in_array($view, ['pending', 'review', 'stockout', 'exceptions', 'shipped'], true)
                ? $view : 'all';

            return '/logistics/oversea/'.$overseas[$route].'?view='.$queue.'&global_grid=1';
        }
        if ($route === 'FACTORY') {
            if ($view === 'shipped') {
                return '/orders?view=shipped&warehouse_route_code=FACTORY';
            }
            $queue = match ($view) {
                'stockout', 'exceptions', 'review' => $view,
                default => 'pending',
            };

            return '/logistics/china?view='.$queue.'&global_grid=1';
        }
        if ($route === self::OUTSOURCE) {
            if (in_array($view, ['exceptions', 'stockout', 'shipped'], true)) {
                return '/orders?view='.($view === 'exceptions' ? 'exception' : $view)
                    .'&warehouse_route_code=OUTSOURCE';
            }

            return '/purchase/external?global_grid=1';
        }
        if ($route === 'TINGON') {
            return '/logistics/tingon?view='.match ($view) {
                'stockout', 'exceptions' => $view,
                'shipped' => 'sent',
                'review' => 'all',
                default => 'pending',
            };
        }

        // A valid but not-yet-exposed specialty warehouse still receives an
        // exact, filterable order list; never land on the wrong provider.
        return '/orders?warehouse_route_code='.rawurlencode($route)
            .'&view='.($view === 'exceptions' ? 'exception' : $phase);
    }

    public function select(Order $order, string $code, int $revision, Authenticatable $actor): array
    {
        abort_unless($actor->can('orders.edit'), 403, '没有修改所属分仓的权限。');
        $allowed = collect($this->options())->pluck('code')->all();
        if (! in_array($code, $allowed, true)) {
            throw new HttpException(422, '所属分仓不在可用选项中。');
        }

        return DB::transaction(function () use ($order, $code, $revision, $actor): array {
            $locked = Order::withTrashed()->with('shipment.warehouse')
                ->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->trashed() || $locked->archived_at !== null || $locked->isOperationallyArchived()) {
                throw new HttpException(409, '归档或删除订单不可修改所属分仓。');
            }
            if ((int) $locked->routing_revision !== $revision) {
                throw new HttpException(409, '所属分仓已被其他人修改，请刷新后重试。');
            }
            if ($locked->routing_warehouse_code === $code) {
                return $this->project($locked);
            }
            $before = $locked->routing_warehouse_code;
            $locked->routing_warehouse_code = $code;
            $locked->routing_revision = $revision + 1;
            $locked->save();

            // Empty shipment is an internal unshipped workbench row only:
            // never assign physical warehouse, inventory, SKU, carrier, tracking or OMS.
            if (! $locked->shipment && $code !== self::UNASSIGNED) {
                $locked->shipment()->create([
                    'warehouse_id' => null, 'shipment_status' => 'pending',
                    'warehouse_selection_source' => 'manual_route_only',
                ]);
            }
            DB::table('order_warehouse_route_histories')->insert([
                'order_id' => $locked->id, 'actor_id' => $actor->getAuthIdentifier(),
                'from_code' => $before, 'to_code' => $code,
                'revision' => $locked->routing_revision, 'created_at' => now(),
            ]);
            $this->audit->record(
                AuditActor::user($actor, AuditSource::Web), 'order.warehouse_route_changed',
                Order::class, $locked->id,
                ['code' => $before, 'revision' => $revision],
                ['code' => $code, 'revision' => $locked->routing_revision],
                'staff_order_work_queue_routing',
            );

            return $this->project($locked->fresh(['shipment.warehouse']));
        });
    }

    /** Order-based workbench scope: logical route wins over legacy Shipment warehouse. */
    public static function orderIn(Builder $orders, string $code, ?int $warehouseId): Builder
    {
        return $orders->where(function (Builder $q) use ($code, $warehouseId): void {
            $q->where('routing_warehouse_code', $code);
            if ($warehouseId !== null) {
                $q->orWhere(fn (Builder $fallback) => $fallback
                    ->whereNull('routing_warehouse_code')
                    ->whereHas('shipment', fn (Builder $s) => $s->where('warehouse_id', $warehouseId)));
            }
        });
    }

    /** Shipment-based workbench scope, including manual-only draft shipment rows. */
    public static function shipmentIn(Builder $shipments, string $code, ?int $warehouseId): Builder
    {
        return $shipments->where(function (Builder $q) use ($code, $warehouseId): void {
            $q->whereHas('order', fn (Builder $o) => $o->where('routing_warehouse_code', $code));
            if ($warehouseId !== null) {
                $q->orWhere(fn (Builder $fallback) => $fallback
                    ->where('warehouse_id', $warehouseId)
                    ->whereHas('order', fn (Builder $o) => $o->whereNull('routing_warehouse_code')));
            }
        });
    }
}
