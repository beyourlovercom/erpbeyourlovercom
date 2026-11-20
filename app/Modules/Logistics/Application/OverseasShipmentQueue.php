<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionDispatchState;
use App\Modules\Orders\Application\OrderManualHoldStatus;
use App\Modules\Orders\Application\OrderProgressQuery;
use App\Modules\Orders\Application\OrderWarehouseRouting;
use Illuminate\Database\Eloquent\Builder;

/** Canonical Shipment-root query shared by the overseas workbench and ERP2 read projection. */
final class OverseasShipmentQueue
{
    public function __construct(private readonly OrderProgressQuery $progressQueries) {}

    public function warehouseMap(): array
    {
        $byCode = Warehouse::query()->where('is_active', true)->get()
            ->filter(fn (Warehouse $warehouse): bool => OverseaWarehouseReviewService::canonicalWarehouseCode($warehouse->code) !== null)
            ->keyBy(fn (Warehouse $warehouse): string => (string) OverseaWarehouseReviewService::canonicalWarehouseCode($warehouse->code));

        return collect(OverseaWarehouseReviewService::WAREHOUSE_CODE_BY_KEY)
            ->mapWithKeys(function (string $code, string $key) use ($byCode): array {
                $warehouse = $byCode->get($code);
                return $warehouse ? [$key => [
                    'key' => $key, 'id' => $warehouse->id, 'code' => $code, 'label' => $warehouse->name,
                    'carrier' => OverseaWarehouseReviewService::CARRIER_BY_CODE[$code] ?? null,
                    'url' => "/logistics/oversea/{$key}",
                ]] : [];
            })->all();
    }

    public function baseQuery(array $warehouseMap, ?array $selectedWarehouse = null): Builder
    {
        $codes = collect($warehouseMap)->pluck('code')->all();
        $ids = collect($warehouseMap)->pluck('id')->all();
        $query = Shipment::query()
            ->where(function ($route) use ($codes, $ids): void {
                $route->whereHas('order', fn ($o) => $o->whereIn('routing_warehouse_code', $codes))
                    ->orWhere(fn ($physical) => $physical->whereIn('warehouse_id', $ids)
                        ->whereHas('order', fn ($o) => $o->whereNull('routing_warehouse_code')));
            })
            ->whereHas('order', fn ($q) => $q->eligibleForOperations()->where('order_status', '!=', 'cancelled')
                ->whereDoesntHave('externalReferences', fn ($r) => $r->where('provider', 'historical_logistics')));
        if ($selectedWarehouse) {
            OrderWarehouseRouting::shipmentIn($query, $selectedWarehouse['code'], (int) $selectedWarehouse['id']);
        }
        return $query;
    }

    public function forView(Builder $query, string $view): Builder
    {
        $this->applyView($query, $view);
        return $query;
    }

    public function applyView($query, string $view): void
    {
        if ($view === 'review') { $this->progressQueries->overseasReview($query); return; }
        if ($view === 'stockout') { $this->progressQueries->overseasStockout($query); return; }
        if ($view === 'pending') {
            $query->where('shipment_status', 'pending')->whereNull('shipped_at');
            $query->where(function ($phase): void {
                $phase->whereHas('order', fn (Builder $o) => $o->where('workflow_status', 'sending'))
                    ->orWhere(function ($auto): void {
                        $this->progressQueries->overseasReady($auto)->where(function ($warehouses): void {
                            $warehouses->whereHas('warehouse', fn ($w) => $w->whereNotIn('code', ['US-LA', 'US-SM']))
                                ->orWhere(fn ($sm) => $sm->whereHas('warehouse', fn ($w) => $w->where('code', 'US-SM'))
                                    ->whereNotExists(fn ($q) => $this->scope3smIssued($q))->whereNotExists(fn ($q) => $this->scope3smException($q)))
                                ->orWhere(function ($moreplus): void {
                                    $moreplus->whereHas('warehouse', fn ($w) => $w->where('code', 'US-LA'))
                                        ->whereNotExists(fn ($sub) => ShipOutProductionDispatchState::scopeCreated($sub))
                                        ->whereNotExists(fn ($sub) => ShipOutProductionDispatchState::scopeOmsException($sub))
                                        ->whereNotExists(fn ($sub) => ShipOutProductionDispatchState::scopeBatchQuarantined($sub))
                                        ->whereNotExists(function ($sub): void { ShipOutProductionDispatchState::scopeAttempt($sub); $sub->where('shipout_attempt.status', '!=', 'acknowledged'); });
                                });
                        });
                    });
            });
            return;
        }
        if ($view === 'issued') {
            $query->whereHas('order', fn (Builder $o) => $o->where(fn (Builder $w) => $w->whereNull('workflow_status')->orWhereNotIn('workflow_status', OrderManualHoldStatus::PRIMARY_CODES)))
                ->where('shipment_status', 'pending')
                ->whereHas('overseaReview', fn ($r) => $r->where('status', 'approved')->whereColumn('reviewed_warehouse_id', 'shipments.warehouse_id'))
                ->where(function ($byWarehouse): void {
                    $byWarehouse->where(fn ($sm) => $sm->whereHas('warehouse', fn ($w) => $w->where('code', 'US-SM'))->whereExists(fn ($sub) => $this->scope3smIssued($sub)))
                        ->orWhere(fn ($moreplus) => $moreplus->whereHas('warehouse', fn ($w) => $w->where('code', 'US-LA'))
                            ->whereExists(fn ($sub) => ShipOutProductionDispatchState::scopeCreated($sub))
                            ->whereNotExists(fn ($sub) => ShipOutProductionDispatchState::scopeBatchQuarantined($sub)));
                });
            return;
        }
        if ($view === 'shipped') {
            $query->whereHas('order', fn (Builder $o) => $o->where(fn (Builder $w) => $w->whereNull('workflow_status')->orWhereNotIn('workflow_status', OrderManualHoldStatus::PRIMARY_CODES)))
                ->where('shipment_status', 'shipped');
            return;
        }
        if ($view === 'exceptions') {
            $query->whereHas('order', fn (Builder $o) => $o->where(fn (Builder $w) => $w->whereNull('workflow_status')
                ->orWhereNotIn('workflow_status', OrderManualHoldStatus::PRIMARY_CODES)->orWhereIn('workflow_status', OrderManualHoldStatus::EXCEPTION_CODES)));
            $query->where(function ($exceptions): void {
                $exceptions->whereHas('order', fn (Builder $orders) => $this->progressQueries->applyPhase($orders, 'exception'))
                    ->orWhere('shipment_status', 'exception')
                    ->orWhere(fn ($sm) => $sm->where('shipment_status', 'pending')->whereHas('warehouse', fn ($w) => $w->where('code', 'US-SM'))->whereExists(fn ($q) => $this->scope3smException($q)))
                    ->orWhere(function ($unresolved): void {
                        $unresolved->where('shipment_status', 'pending')->whereHas('warehouse', fn ($w) => $w->where('code', 'US-LA'))
                            ->where(function ($problem): void {
                                $problem->whereExists(fn ($sub) => ShipOutProductionDispatchState::scopeOmsException($sub))
                                    ->orWhereExists(function ($sub): void {
                                        ShipOutProductionDispatchState::scopeAttempt($sub);
                                        $sub->where(fn ($notConfirmed) => $notConfirmed->where('shipout_attempt.status', '!=', 'acknowledged')
                                            ->orWhereNull('shipout_attempt.provider_order_id')->orWhere('shipout_attempt.provider_order_id', ''));
                                    })->orWhereExists(fn ($sub) => ShipOutProductionDispatchState::scopeBatchQuarantined($sub));
                            });
                    });
            });
        }
    }

    private function scope3smIssued(\Illuminate\Database\Query\Builder $query): void
    {
        $query->selectRaw('1')->from('xlwms_dispatch_attempts as sm_issued')->whereColumn('sm_issued.order_id', 'shipments.order_id')
            ->whereNotNull('sm_issued.oms_order_no')->where('sm_issued.oms_order_no', '!=', '')
            ->whereIn('sm_issued.oms_status_code', ['2', '3'])->whereNotIn('sm_issued.state', ['oms_draft_unverified', 'batch_draft_unverified']);
    }

    private function scope3smException(\Illuminate\Database\Query\Builder $query): void
    {
        $query->selectRaw('1')->from('xlwms_dispatch_attempts as sm_exception')->whereColumn('sm_exception.order_id', 'shipments.order_id')
            ->where(fn ($exception) => $exception->whereIn('sm_exception.oms_status_code', ['4', '5'])
                ->orWhereIn('sm_exception.state', ['oms_draft_unverified', 'batch_draft_unverified']));
    }
}
