<?php

namespace App\Modules\Orders\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Application\Contracts\InventoryReservationRepository;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\CarrierRoutingPolicy;
use App\Modules\Logistics\Application\PendingFulfillmentReallocationService;
use App\Modules\Logistics\Infrastructure\Models\FulfillmentAllocation;
use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class OrderWarehouseRouting
{
    public const UNASSIGNED = 'UNASSIGNED';

    public const OUTSOURCE = 'OUTSOURCE';

    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly CarrierRoutingPolicy $carriers,
    ) {}

    /** Same active warehouse choices as Vita's manual allocation. */
    public function options(): array
    {
        $codes = (array) config('warehouse_master.fulfillment_codes', []);
        // Production has legacy lowercase codes (notably us-la). The public
        // route contract remains canonical uppercase, including on SQLite UAT.
        $lookupCodes = collect($codes)
            ->flatMap(fn (string $code): array => [$code, strtolower($code)])
            ->unique()->all();
        $known = Warehouse::query()->where('is_active', true)->whereIn('code', $lookupCodes)
            ->get(['code', 'name'])
            ->keyBy(fn (Warehouse $warehouse): string => strtoupper(trim((string) $warehouse->code)));

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
     * Physical warehouse selection still respects fulfillment and review gates.
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

    public function select(Order $order, string $code, int $revision, Authenticatable $actor, bool $stockExceptionAcknowledged = false): array
    {
        abort_unless($actor->can('orders.edit'), 403, '没有修改所属分仓的权限。');
        $allowed = collect($this->options())->pluck('code')->all();
        if (! in_array($code, $allowed, true)) {
            throw new HttpException(422, '所属分仓不在可用选项中。');
        }

        return DB::transaction(function () use ($order, $code, $revision, $actor, $stockExceptionAcknowledged): array {
            $locked = Order::withTrashed()->with('shipment.warehouse')
                ->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($locked->trashed() || $locked->archived_at !== null || $locked->isOperationallyArchived()) {
                throw new HttpException(409, '归档或删除订单不可修改所属分仓。');
            }
            if ((int) $locked->routing_revision !== $revision) {
                throw new HttpException(409, '所属分仓已被其他人修改，请刷新后重试。');
            }
            // Owner rule: the workbench route and actual pending shipment warehouse
            // are one atomic change. Repair legacy mismatches even on same-code saves.
            $warehouse = in_array($code, [self::UNASSIGNED, self::OUTSOURCE], true)
                ? null : Warehouse::query()->whereIn('code', [$code, strtolower($code)])
                    ->where('is_active', true)->firstOrFail();
            $targetWarehouseId = $warehouse?->getKey();
            $shipment = $locked->shipment;
            if ($locked->routing_warehouse_code === $code
                && ($shipment?->warehouse_id === null ? null : (int) $shipment->warehouse_id) === $targetWarehouseId) {
                return $this->project($locked);
            }
            $reactivatedUnassignedChild = $locked->fulfillment_parent_id !== null
                && ($locked->workflow_status === 'pending_allocation'
                    || ($locked->routing_warehouse_code === self::UNASSIGNED
                        && ($shipment === null || $shipment->warehouse_id === null)));
            if ($reactivatedUnassignedChild) {
                abort_unless($warehouse !== null, 409, '重新运营的拆单请先选择实际发货仓库。');
                // A/B children retain their parent allocation and must use the
                // audited single-leg flow; never create a second split plan.
                $updated = app(PendingFulfillmentReallocationService::class)->reallocate(
                    $locked, (int) $warehouse->getKey(), $actor, null, $stockExceptionAcknowledged
                );

                return $this->project($updated->fresh(['shipment.warehouse']));
            }
            $this->assertSafeToReassign($locked, $shipment);
            $this->syncSubmittedAllocation($locked, $warehouse, $code, $actor);

            $before = $locked->routing_warehouse_code;
            $fromWarehouseId = $shipment?->warehouse_id;
            if (! $shipment) {
                $targetCode = strtoupper(trim((string) ($warehouse?->code ?? '')));
                $route = $warehouse
                    ? $this->carriers->warehouseRoute($locked->address?->country, $targetCode)
                    : [];
                $shipment = $locked->shipment()->create([
                    'warehouse_id' => $targetWarehouseId,
                    'shipment_status' => 'pending',
                    'warehouse_selection_source' => $warehouse ? 'manual' : 'manual_route_only',
                    'carrier' => $route['carrier'] ?? null,
                    'service' => $route['service'] ?? null,
                    'carrier_service_id' => $route['carrier_service_id'] ?? null,
                    'carrier_selection_source' => filled($route['carrier'] ?? null) ? 'automatic' : null,
                ]);
            } elseif (($shipment->warehouse_id === null ? null : (int) $shipment->warehouse_id) !== $targetWarehouseId) {
                $targetCode = strtoupper(trim((string) ($warehouse?->code ?? '')));
                $route = $warehouse
                    ? $this->carriers->warehouseRoute($locked->address?->country, $targetCode)
                    : [];
                // A carrier/service is bound to its old physical warehouse.
                // Never carry FACTORY 4PX into US-SM or US-NY into MorePlus.
                $shipment->forceFill([
                    'warehouse_id' => $targetWarehouseId,
                    'warehouse_selection_source' => $warehouse ? 'manual' : 'manual_route_only',
                    'carrier' => $route['carrier'] ?? null,
                    'service' => $route['service'] ?? null,
                    'carrier_service_id' => $route['carrier_service_id'] ?? null,
                    'carrier_selection_source' => filled($route['carrier'] ?? null) ? 'automatic' : null,
                    'handled_by' => null,
                ])->save();
            }
            if (($fromWarehouseId === null ? null : (int) $fromWarehouseId) !== $targetWarehouseId) {
                $this->resetOverseaReview($locked, $targetWarehouseId);
            }
            $locked->routing_warehouse_code = $code;
            $locked->routing_revision = $revision + 1;
            $locked->save();
            DB::table('order_warehouse_route_histories')->insert([
                'order_id' => $locked->id, 'actor_id' => $actor->getAuthIdentifier(),
                'from_code' => $before, 'to_code' => $code,
                'revision' => $locked->routing_revision, 'created_at' => now(),
            ]);
            $this->audit->record(
                AuditActor::user($actor, AuditSource::Web), 'order.warehouse_route_changed',
                Order::class, $locked->id,
                ['code' => $before, 'revision' => $revision, 'warehouse_id' => $fromWarehouseId],
                ['code' => $code, 'revision' => $locked->routing_revision, 'warehouse_id' => $targetWarehouseId],
                'staff_order_physical_warehouse_routing',
            );

            return $this->project($locked->fresh(['shipment.warehouse']));
        });
    }

    /**
     * Never rewrite an in-flight physical dispatch. A split child, warehouse
     * reservation or already-submitted OMS order requires its dedicated flow.
     */
    private function assertSafeToReassign(Order $order, $shipment): void
    {
        abort_unless($order->order_status?->value === 'pending_shipment', 409,
            '只有待发货订单可以直接调整实际发货仓。');
        abort_if($order->fulfillment_parent_id !== null
            || $order->fulfillmentChildren()->withTrashed()->exists(), 409,
            '拆分或补发订单请在履约发货流程中单独调整仓库。');
        abort_if($shipment && ($shipment->shipment_status !== 'pending'
            || filled($shipment->tracking_number) || $shipment->shipped_at !== null), 409,
            '已有真实物流或追踪单号，禁止直接修改发货仓。');
        abort_if($order->shipmentAttempts()->exists()
            || DB::table('shipout_dispatch_attempts')->where('order_id', $order->id)->exists(), 409,
            '存在已提交仓库或历史物流尝试，禁止通过所属分仓直接改仓。');
        abort_if($order->procurementOrder()->where('origin', 'external')
            ->whereNull('released_at')->exists(), 409,
            '外采订单尚未确认到货，不能直接改为普通仓库发货。');
        abort_if(app(InventoryReservationRepository::class)->activeForOrder((int) $order->id), 409,
            '订单有已锁定库存，请通过正式分仓流程调整并重新校验库存。');
    }

    /** Keep one-leg historical allocation metadata consistent without remapping SKU. */
    private function syncSubmittedAllocation(
        Order $order, ?Warehouse $warehouse, string $code, Authenticatable $actor,
    ): void {
        $allocation = FulfillmentAllocation::query()->where('order_id', $order->id)->lockForUpdate()->first();
        if (! $allocation || $allocation->status !== 'submitted') {
            return;
        }
        abort_if($warehouse === null, 409, '已有正式分仓方案，请通过待分仓流程撤销分仓。');
        $snapshot = is_array($allocation->plan_snapshot) ? $allocation->plan_snapshot : [];
        $segments = array_values((array) ($snapshot['segments'] ?? []));
        $derived = array_values((array) ($snapshot['derived_orders'] ?? []));
        abort_unless(count($segments) === 1 && ($snapshot['is_split'] ?? false) === false
            && (count($derived) === 0 || (count($derived) === 1
                && (int) ($derived[0]['order_id'] ?? 0) === (int) $order->id)), 409,
            '分仓方案包含多段履约或衍生订单，请使用履约分仓功能调整。');
        $segment = &$segments[0];
        abort_if(($segment['procurement_required'] ?? false) === true, 409,
            '已生成外采方案，请使用外采流程调整。');
        $segment['source_code'] = $code;
        $segment['source_type'] = $code === 'FACTORY' ? 'factory' : 'warehouse';
        $segment['warehouse_id'] = (int) $warehouse->id;
        $segment['warehouse_name'] = (string) $warehouse->name;
        $route = $this->carriers->warehouseRoute($order->address?->country, $code);
        $segment['carrier'] = $route['carrier'] ?? null;
        $segment['service'] = $route['service'] ?? null;
        $segment['carrier_selection_source'] = filled($route['carrier'] ?? null) ? 'automatic' : null;
        unset($segment);
        $snapshot['segments'] = $segments;
        $snapshot['plan_type'] = $code === 'FACTORY' ? 'factory' : 'single';
        if ($derived !== []) {
            $derived[0]['warehouse_code'] = $code;
            $derived[0]['warehouse_id'] = (int) $warehouse->id;
            $derived[0]['warehouse_name'] = (string) $warehouse->name;
            $derived[0]['carrier'] = $route['carrier'] ?? null;
            $derived[0]['service'] = $route['service'] ?? null;
            $derived[0]['carrier_selection_source'] = filled($route['carrier'] ?? null) ? 'automatic' : null;
            $snapshot['derived_orders'] = $derived;
        }
        $assignments = is_array($allocation->manual_assignments) ? $allocation->manual_assignments : null;
        if ($assignments !== null) {
            foreach ($assignments as &$assignment) {
                $assignment['source_code'] = $code;
                $assignment['carrier'] = $route['carrier'] ?? null;
                $assignment['service'] = $route['service'] ?? null;
                $assignment['carrier_selection_source'] = filled($route['carrier'] ?? null) ? 'automatic' : null;
            }
            unset($assignment);
        }
        $snapshot['plan_key'] = 'route:'.substr(hash('sha256', json_encode($segments)), 0, 16);
        $snapshot['execution_status'] = 'manual_route_physical_reassigned';
        $allocation->forceFill([
            'decision_source' => 'manual',
            'selected_plan_key' => $snapshot['plan_key'],
            'plan_snapshot' => $snapshot,
            'manual_assignments' => $assignments,
            'submitted_by' => $actor->getAuthIdentifier(),
            'submitted_at' => now(),
        ])->save();
    }

    private function resetOverseaReview(Order $order, ?int $warehouseId): void
    {
        $review = OverseaWarehouseReview::query()
            ->where('order_id', $order->id)->lockForUpdate()->first();
        if (! $review) {
            return;
        }
        $review->forceFill([
            'status' => 'pending',
            'progress' => 'pending_review',
            'submitted_warehouse_id' => $warehouseId,
            'reviewed_warehouse_id' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ])->save();
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
