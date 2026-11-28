<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionOmsReadbackService;
use App\Modules\Logistics\Application\Xlwms\DeTianyunPreflightRecovery;
use App\Modules\Logistics\Application\Xlwms\SingleOrderDispatch;
use App\Modules\Logistics\Application\Xlwms\UsNyOutboundBatch;
use App\Modules\Logistics\Application\Xlwms\UsNyTrackingStage;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;
use App\Modules\Orders\Application\OrderManualHoldStatus;
use App\Modules\Orders\Application\OrderProgressQuery;
use App\Modules\Orders\Application\OrderProgressResolver;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderWorkflowStatusHistory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/** Operator acknowledgement of a local failure, or reconciliation of the same OMS order. Never creates an OMS order. */
final class WarehouseExceptionResolution
{
    public const RETRY_RELEASED = 'warehouse_retry_released';

    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly OverseaWarehouseQueueQuery $queues,
        private readonly AuditRecorder $audit,
    ) {}

    public function resolve(Order $order, Authenticatable $actor, int $revision): array
    {
        abort_unless(app(LogisticsExecutionAccess::class)->allows($actor), 403);
        $id = (int) $order->id;
        $order = Order::query()->eligibleForOperations()->with('shipment.warehouse')->findOrFail($id);
        abort_if($order->originalFulfillmentChildren()->exists(), 409, '拆单母单不执行履约操作，请处理实际子单。');
        abort_unless((int) $order->workflow_revision === $revision, 409, '订单状态已变化，请刷新重试。');
        $code = (string) $order->shipment?->warehouse?->code;
        $oversea = OverseaWarehouseReviewService::canonicalWarehouseCode($code) !== null;
        abort_unless($oversea || $code === 'FACTORY', 409, '请在实际所属仓库处理异常。');
        $isException = fn () => $oversea
            ? $this->queues->destination($id) === 'exceptions'
            : Order::query()->whereKey($id)->where(function ($q) {
                $q->whereHas('shipment', fn ($s) => $s->where('shipment_status', 'exception'))
                    ->orWhere(fn ($m) => app(OrderProgressQuery::class)->applyPhase($m, 'exception'))
                    ->orWhere(fn ($m) => app(OrderProgressQuery::class)->applyPhase($m, 'progress_conflict'));
            })->exists();
        abort_unless($isException(), 409, '订单已不在异常队列，请刷新页面。');

        // An already created OMS order is only reconciled, not re-created.
        $attempt = XlwmsDispatchAttempt::where('order_id', $id)->first();
        if ($attempt && filled($attempt->oms_order_no) && in_array($code, ['US-NY', 'DE', 'US-SM'], true)) {
            abort_unless((int) $attempt->warehouse_id === (int) $order->shipment->warehouse_id, 409, '历史 OMS 仓库不匹配，请核对原单。');
            try {
                $read = $code === 'US-NY' && $attempt->state === 'oms_create_confirmed'
                    ? app(UsNyTrackingStage::class)->refreshAndStage($id)
                    : app(SingleOrderDispatch::class)->checkStatus($id);
            } catch (\Throwable) {
                abort(409, '仓库原单查询失败，已保留异常。');
            }
            abort_unless($read['fresh'] ?? false, 409, '仓库原单查询失败，已保留异常。');
        } elseif ($code === 'US-LA' && DB::table('shipout_dispatch_attempts')->where('order_id', $id)->exists()) {
            try {
                app(ShipOutProductionOmsReadbackService::class)->inspectAndRecord($id);
            } catch (\Throwable) {
                abort(409, '仓库原单查询失败，已保留异常。');
            }
        }

        return DB::transaction(function () use ($id, $revision, $actor, $code, $oversea, $isException) {
            $o = Order::query()->eligibleForOperations()->with('operationalMarkers')->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($o->originalFulfillmentChildren()->exists(), 409, '拆单母单不可解除履约异常。');
            abort_unless((int) $o->workflow_revision === $revision, 409, '订单状态已变化，请刷新重试。');
            $s = $o->shipment()->lockForUpdate()->firstOrFail();
            abort_unless($s->warehouse?->code === $code, 409, '订单仓库已变化，请刷新重试。');
            $before = ['workflow_status' => $o->workflow_status, 'shipment_status' => $s->shipment_status];
            $prior = XlwmsDispatchAttempt::where('order_id', $id)->lockForUpdate()->first();
            $release = $s->shipment_status === 'pending' && blank($s->tracking_number) && $s->shipped_at === null
                && $o->order_status?->value === 'pending_shipment'
                && match ($code) {
                    'DE' => $prior && DeTianyunPreflightRecovery::eligible($prior, $o, (int) $prior->id)
                        && ! DB::table('xlwms_de_dispatch_batch_items')->where('order_id', $id)->whereIn('status', ['queued', 'processing'])->exists(),
                    'US-NY' => $prior && UsNyOutboundBatch::recoveryEligible($prior, $o) && ! UsNyOutboundBatch::guarded($id),
                    'US-LA' => $this->morePlusPreCreateOnly($id),
                    default => false,
                };
            if ($release) {
                $o->operationalMarkers()->updateOrCreate(['marker' => self::RETRY_RELEASED],
                    ['marked_by' => $actor->getAuthIdentifier(), 'marked_at' => now()]);
            }
            $hold = OrderManualHoldStatus::selected($o);
            if (in_array($hold, OrderManualHoldStatus::EXCEPTION_CODES, true)) {
                $o->operationalMarkers()->whereIn('marker', OrderManualHoldStatus::EXCEPTION_MARKERS)->delete();
                if (in_array($o->workflow_status, OrderManualHoldStatus::EXCEPTION_CODES, true)) {
                    $o->workflow_status = null;
                }
                $o->workflow_revision = (int) $o->workflow_revision + 1;
                $o->save();
                $o->unsetRelation('operationalMarkers');
                $restored = app(OrderProgressResolver::class)->resolve($o->fresh()) ?? 'pending_review';
                OrderWorkflowStatusHistory::query()->create([
                    'order_id' => $id, 'actor_id' => $actor->getAuthIdentifier(), 'from_status' => $hold,
                    'to_status' => $restored, 'revision' => $o->workflow_revision,
                ]);
            }
            if ($s->shipment_status === 'exception') {
                abort_unless($s->shipped_at !== null && filled($s->tracking_number), 409, '缺少真实出库证据，请先核对物流记录。');
                $this->shipments->resolveException($o, $actor);
            }
            abort_if($isException(), 409, '异常原因仍存在，请核实仓库原单或修正订单信息；未重复推单。');
            $destination = $oversea ? $this->queues->destination($id) : 'domestic';
            $this->audit->record(AuditActor::user($actor, AuditSource::Web), 'logistics.warehouse_exception_resolved',
                Order::class, $id, $before, ['destination' => $destination, 'precreate_retry_released' => $release,
                    'provider_attempt_id' => $prior?->id], 'operator_confirmed_restore_actual_progress');

            return ['destination' => $destination, 'message' => $release ? '异常已解除，可人工再次推送原订单。' : '异常已解除，已按真实进度恢复。'];
        });
    }

    private function morePlusPreCreateOnly(int $id): bool
    {
        if (DB::table('shipout_dispatch_attempts')->where('order_id', $id)->exists()
            || DB::table('shipout_oms_readbacks')->where('order_id', $id)->exists()) {
            return false;
        }
        $items = DB::table('shipout_production_batch_items as i')
            ->join('shipout_production_batches as b', 'b.id', '=', 'i.batch_id')
            ->where('i.order_id', $id)->where('b.dry_run', false)
            ->get(['i.status', 'i.provider_order_id', 'b.status as batch_status']);

        return $items->isNotEmpty() && $items->every(fn ($i) => $i->status === 'skipped'
            && blank($i->provider_order_id) && in_array($i->batch_status, ['completed', 'completed_partial'], true));
    }
}
