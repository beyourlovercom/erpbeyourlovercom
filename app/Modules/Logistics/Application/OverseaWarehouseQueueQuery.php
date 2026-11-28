<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Logistics\Application\ShipOut\ShipOutProductionDispatchState;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Application\OrderManualHoldStatus;
use App\Modules\Orders\Application\OrderProgressQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class OverseaWarehouseQueueQuery
{
    public function __construct(private readonly OrderProgressQuery $progressQueries) {}

    public function destination(int $orderId): ?string
    {
        foreach (['review', 'stockout', 'pending', 'issued', 'shipped', 'exceptions'] as $view) {
            $query = Shipment::query()->where('order_id', $orderId);
            $this->applyView($query, $view);
            if ($query->exists()) {
                return $view;
            }
        }

        return null;
    }

    /**
     * Real provider facts win over an ordinary Sending label. The same
     * predicates are used by the workbench, counters and export.
     * An unresolved provider attempt is not authorization to retry.
     */
    public function applyView($query, string $view): void
    {
        if ($view === 'review') {
            $this->progressQueries->overseasReview($query);
            $query->whereNot(fn ($q) => $this->providerIssued($q))
                ->where(function ($reviewable): void {
                    $reviewable->whereNot(fn ($q) => $this->providerException($q))
                        // A proven pre-CREATE failure must not hide a reopened
                        // warehouse review. Approving still cannot submit OMS.
                        ->orWhere(function ($preCreate): void {
                            $preCreate->whereHas('warehouse', fn ($w) => $w->whereIn('code', ['DE', 'US-NY']))
                                ->whereExists(function ($attempt): void {
                                    $this->scopeDeAttempt($attempt);
                                    $attempt->where('de_attempt.state', 'blocked_preflight_error')
                                        ->whereIn('de_attempt.failure_code', ['DE_OMS_PREFLIGHT_OR_DUPLICATE_LOOKUP_FAILED', 'US_NY_PREFLIGHT_OR_DUPLICATE_BLOCKED'])
                                        ->whereNull('de_attempt.oms_order_no')->whereNull('de_attempt.oms_status_code')
                                        ->whereNull('de_attempt.oms_tracking_no')->whereNull('de_attempt.oms_status_checked_at')
                                        ->whereNull('de_attempt.duplicate_match_field')->whereNull('de_attempt.oms_status_failure_code');
                                });
                        });
                });

            return;
        }
        if ($view === 'stockout') {
            $this->progressQueries->overseasStockout($query);
            $query->whereNot(fn ($q) => $this->providerIssued($q))
                ->whereNot(fn ($q) => $this->providerException($q));

            return;
        }
        if ($view === 'pending') {
            $query->where('shipment_status', 'pending')->whereNull('shipped_at');
            $this->progressQueries->overseasReady($query);
            $query->whereNot(fn ($q) => $this->providerIssued($q))
                ->whereNot(fn ($q) => $this->providerException($q));

            return;
        }
        if ($view === 'issued') {
            $query->whereHas('order', fn ($o) => $this->withoutOtherManualQueue($o))
                ->where('shipment_status', 'pending')
                ->whereHas('overseaReview', fn ($r) => $r->where('status', 'approved')
                    ->whereColumn('reviewed_warehouse_id', 'shipments.warehouse_id'));
            $this->providerIssued($query);
            $query->whereNot(fn ($q) => $this->providerException($q));

            return;
        }
        if ($view === 'shipped') {
            $query->whereHas('order', fn ($o) => $this->withoutOtherManualQueue($o))
                ->where('shipment_status', 'shipped');

            return;
        }
        if ($view === 'exceptions') {
            $query->where(function ($exceptions): void {
                // Provider failures are factual, including when staff chose
                // Sending or a stockout hold. Never hide a failed OMS attempt.
                $exceptions->where(fn ($q) => $this->providerException($q))
                    ->orWhere(function ($conflict): void {
                        $conflict->where('shipment_status', 'pending');
                        $this->providerIssued($conflict);
                        $conflict->where(function ($mismatch): void {
                            $mismatch->whereDoesntHave('overseaReview', fn ($r) => $r->where('status', 'approved')
                                ->whereColumn('reviewed_warehouse_id', 'shipments.warehouse_id'))
                                ->orWhereHas('order', fn ($o) => $o->whereIn('workflow_status',
                                    array_values(array_diff(OrderManualHoldStatus::PRIMARY_CODES, ['sending']))));
                        });
                    })->orWhere(function ($manual): void {
                        $manual->whereHas('order', fn ($o) => $this->progressQueries->applyPhase($o, 'exception'))
                            ->orWhere('shipment_status', 'exception');
                    })->orWhere(function ($orphan): void {
                        // Fail closed for an unfinished routed order that has
                        // no operational bucket. Legitimate Vita/return queues
                        // remain separate; the fallback must never enable send.
                        $orphan->where('shipment_status', 'pending')->whereNull('shipped_at')
                            ->whereHas('order', fn ($o) => $o->where('order_status', 'pending_shipment')
                                ->whereNot(fn ($q) => $this->progressQueries->pendingAllocation($q))
                                ->whereNot(fn ($q) => $this->progressQueries->applyPhase($q, 'returned_follow_up')));
                    });
            });
            // Explicit manual exception vs other real work queues: an active
            // provider conflict is never actionable, and a genuine shipped
            // record remains separate from the pending fallback.
            foreach (['review', 'stockout', 'pending', 'issued', 'shipped'] as $other) {
                $query->whereNot(fn ($q) => $this->applyView($q, $other));
            }
        }
    }

    private function withoutOtherManualQueue($orders): void
    {
        $orders->where(fn ($w) => $w->whereNull('workflow_status')
            ->orWhereNotIn('workflow_status', array_values(array_diff(OrderManualHoldStatus::PRIMARY_CODES, ['sending']))));
    }

    private function providerIssued($query): void
    {
        $query->where(function ($byWarehouse): void {
            $byWarehouse->where(function ($sm): void {
                $sm->whereHas('warehouse', fn ($w) => $w->whereRaw('UPPER(code) = ?', ['US-SM']))
                    ->whereExists(fn ($q) => $this->scope3smIssued($q));
            })->orWhere(function ($xlwms): void {
                $xlwms->whereHas('warehouse', fn ($w) => $w->whereIn(DB::raw('UPPER(code)'), ['DE', 'US-NY']))
                    ->whereExists(fn ($q) => $this->scopeDeIssued($q));
            })->orWhere(function ($moreplus): void {
                $moreplus->whereHas('warehouse', fn ($w) => $w->whereRaw('UPPER(code) = ?', ['US-LA']))
                    ->whereExists(fn ($q) => ShipOutProductionDispatchState::scopeCreated($q))
                    ->whereNotExists(fn ($q) => ShipOutProductionDispatchState::scopeBatchQuarantined($q));
            });
        });
    }

    private function providerException($query): void
    {
        $query->where('shipment_status', 'pending')->where(function ($byWarehouse): void {
            $byWarehouse->where(function ($sm): void {
                $sm->whereHas('warehouse', fn ($w) => $w->whereRaw('UPPER(code) = ?', ['US-SM']))
                    ->whereExists(fn ($q) => $this->scope3smException($q));
            })->orWhere(function ($xlwms): void {
                $xlwms->whereHas('warehouse', fn ($w) => $w->whereIn(DB::raw('UPPER(code)'), ['DE', 'US-NY']))
                    ->whereExists(function ($q): void {
                        $this->scopeDeAttempt($q);
                        $q->whereNot(function ($released): void {
                            $released->where('de_attempt.state', 'blocked_preflight_error')
                                ->whereIn('de_attempt.failure_code', ['DE_OMS_PREFLIGHT_OR_DUPLICATE_LOOKUP_FAILED', 'US_NY_PREFLIGHT_OR_DUPLICATE_BLOCKED'])
                                ->whereNull('de_attempt.oms_order_no')->whereNull('de_attempt.oms_status_code')
                                ->whereNull('de_attempt.oms_tracking_no')->whereNull('de_attempt.oms_status_checked_at')
                                ->whereNull('de_attempt.duplicate_match_field')->whereNull('de_attempt.oms_status_failure_code')
                                ->whereExists(fn ($m) => $m->selectRaw('1')->from('order_operational_markers as released')
                                    ->whereColumn('released.order_id', 'de_attempt.order_id')
                                    ->where('released.marker', WarehouseExceptionResolution::RETRY_RELEASED)
                                    ->whereColumn('released.marked_at', '>=', 'de_attempt.updated_at'));
                        });
                    })
                    ->whereNotExists(fn ($q) => $this->scopeDeIssued($q));
            })->orWhere(function ($ny): void {
                $ny->whereHas('warehouse', fn ($w) => $w->where('code', 'US-NY'))
                    ->whereNotExists(fn ($q) => $this->scopeDeIssued($q))
                    ->whereExists(function ($q): void {
                        $q->selectRaw('1')->from('xlwms_ny_dispatch_batch_items as ni')
                            ->join('xlwms_ny_dispatch_batches as nb', 'nb.id', '=', 'ni.batch_id')
                            ->whereColumn('ni.order_id', 'shipments.order_id')->where('nb.dry_run', false)
                            ->whereIn('ni.status', ['queued', 'processing', 'uncertain']);
                    });
            })->orWhere(function ($moreplus): void {
                $moreplus->whereHas('warehouse', fn ($w) => $w->whereRaw('UPPER(code) = ?', ['US-LA']))
                    ->where(function ($problem): void {
                        $problem->whereExists(fn ($q) => ShipOutProductionDispatchState::scopeOmsException($q))
                            ->orWhereExists(fn ($q) => ShipOutProductionDispatchState::scopeBatchQuarantined($q))
                            ->orWhereExists(function ($q): void {
                                ShipOutProductionDispatchState::scopeAttempt($q);
                                $q->where(fn ($bad) => $bad->where('shipout_attempt.status', '!=', 'acknowledged')
                                    ->orWhereNull('shipout_attempt.provider_order_id')
                                    ->orWhere('shipout_attempt.provider_order_id', ''));
                            });
                    });
            });
        });
    }

    private function scopeDeAttempt(Builder $query): void
    {
        $query->selectRaw('1')->from('xlwms_dispatch_attempts as de_attempt')
            ->whereColumn('de_attempt.order_id', 'shipments.order_id')
            ->whereColumn('de_attempt.warehouse_id', 'shipments.warehouse_id');
    }

    private function scopeDeIssued(Builder $query): void
    {
        $this->scopeDeAttempt($query);
        $query->where('de_attempt.state', 'oms_create_confirmed')
            ->whereNotNull('de_attempt.oms_order_no')->where('de_attempt.oms_order_no', '!=', '')
            ->where(function ($status): void {
                $status->whereIn('de_attempt.oms_status_code', ['2', '3'])
                    // NY automatic label acquisition is asynchronous. A freshly
                    // created draft is not a failed CREATE or a warehouse receipt.
                    ->orWhere(function ($label): void {
                        $label->where('de_attempt.oms_status_code', '0')
                            ->whereNull('de_attempt.oms_status_failure_code')
                            ->where('de_attempt.created_at', '>=', now()->subMinutes(30))
                            ->whereExists(fn ($w) => $w->selectRaw('1')->from('warehouses as ny_label_warehouse')
                                ->whereColumn('ny_label_warehouse.id', 'de_attempt.warehouse_id')->where('ny_label_warehouse.code', 'US-NY'));
                    });
            });
    }

    /** 3SM: an OMS draft (status 0) is not a warehouse-dispatched order. */
    private function scope3smIssued(Builder $query): void
    {
        $query->selectRaw('1')->from('xlwms_dispatch_attempts as sm_issued')
            ->whereColumn('sm_issued.order_id', 'shipments.order_id')
            ->whereColumn('sm_issued.warehouse_id', 'shipments.warehouse_id')
            ->whereNotNull('sm_issued.oms_order_no')
            ->where('sm_issued.oms_order_no', '!=', '')
            ->whereIn('sm_issued.oms_status_code', ['2', '3'])
            ->whereNotIn('sm_issued.state', ['oms_draft_unverified', 'batch_draft_unverified']);
    }

    private function scope3smException(Builder $query): void
    {
        $query->selectRaw('1')->from('xlwms_dispatch_attempts as sm_exception')
            ->whereColumn('sm_exception.order_id', 'shipments.order_id')
            ->whereColumn('sm_exception.warehouse_id', 'shipments.warehouse_id')
            ->where(function ($exception): void {
                $exception->whereIn('sm_exception.oms_status_code', ['4', '5'])
                    ->orWhereIn('sm_exception.state', ['oms_draft_unverified', 'batch_draft_unverified'])
                    ->orWhere('sm_exception.state', 'like', '%uncertain%')
                    ->orWhere('sm_exception.state', 'like', '%in_flight%')
                    ->orWhereNotNull('sm_exception.failure_code');
            });
    }
}
