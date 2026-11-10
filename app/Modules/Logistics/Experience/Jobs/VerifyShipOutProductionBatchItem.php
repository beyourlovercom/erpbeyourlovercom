<?php

namespace App\Modules\Logistics\Experience\Jobs;

use App\Modules\Logistics\Application\ShipOut\ShipOutProductionOmsReadbackService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * GET-only verification of an existing, immutable ShipOut POST ACK.
 * Never inserts a new attempt, never submits an OMS order and never marks shipped.
 * Up to three signed readbacks; uncertain outcomes end in manual review.
 */
final class VerifyShipOutProductionBatchItem implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(
        public readonly string $batchId,
        public readonly int $orderId,
        public readonly int $check = 1,
    ) {}

    public function handle(ShipOutProductionOmsReadbackService $reconcile): void
    {
        $row = DB::table('shipout_production_batch_items')
            ->where('batch_id', $this->batchId)
            ->where('order_id', $this->orderId)
            ->first(['id', 'status', 'provider_order_id']);
        if (! $row || ! in_array($row->status, ['pending_oms', 'oms_visible_provisional'], true)) {
            return;
        }

        $terminal = false;
        $newStatus = 'pending_oms';
        $code = 'OMS_READBACK_PENDING';
        try {
            $result = $reconcile->inspectAndRecord($this->orderId);
            if (filled($row->provider_order_id) && (string) $result['provider_order_id'] !== (string) $row->provider_order_id) {
                throw new \RuntimeException('OMS_PROVIDER_ID_MISMATCH');
            }

            if ($result['reconciliation'] === 'PROVIDER_CANCELLED_OR_ABORTED_MANUAL_REVIEW') {
                $newStatus = 'provider_cancelled';
                $candidate = (string) ($result['provider_failure_code'] ?? '');
                $code = in_array($candidate, [
                    'PROVIDER_DUPLICATE_ORDER', 'PROVIDER_EZEESHIP_SHIPPING_ERROR',
                    'PROVIDER_CANCELLED_OTHER',
                ], true) ? $candidate : 'PROVIDER_CANCELLED_OTHER';
                $terminal = true;
            } elseif ($result['reconciliation'] === 'ORDER_VISIBLE_MANUAL_REVIEW_ONLY') {
                if ($this->check >= 2) {
                    $newStatus = 'oms_visible';
                    $code = 'OMS_VISIBLE_PENDING_REAL_FULFILLMENT';
                    $terminal = true;
                } else {
                    $newStatus = 'oms_visible_provisional';
                    $code = 'OMS_VISIBLE_PROVISIONAL_RECHECK';
                }
            } elseif ($this->check >= 3) {
                $newStatus = 'needs_review';
                $code = 'OMS_READBACK_NOT_CONFIRMED_MANUAL_CHECK';
                $terminal = true;
            }
        } catch (Throwable) {
            if ($this->check >= 3) {
                $newStatus = 'needs_review';
                $code = 'OMS_READBACK_UNAVAILABLE_MANUAL_CHECK';
                $terminal = true;
            } else {
                $newStatus = 'pending_oms';
                $code = 'OMS_READBACK_RETRY_GET_ONLY';
            }
        }

        $updated = DB::table('shipout_production_batch_items')
            ->where('id', $row->id)
            ->whereIn('status', ['pending_oms', 'oms_visible_provisional'])
            ->update(['status' => $newStatus, 'result_code' => $code, 'updated_at' => now()]);
        if ($updated !== 1) {
            return;
        }

        if (! $terminal) {
            // Recheck a provisional live OMS row after async processing has had
            // time to cancel it, or recheck a delayed/unavailable provider GET.
            self::dispatch($this->batchId, $this->orderId, $this->check + 1)
                ->onQueue(ProcessShipOutProductionBatch::QUEUE)
                ->delay(now()->addSeconds($newStatus === 'oms_visible_provisional' ? 120 : 60));
        }
        $this->completeBatchIfReconciled();
    }

    private function completeBatchIfReconciled(): void
    {
        $outstanding = DB::table('shipout_production_batch_items')
            ->where('batch_id', $this->batchId)
            ->whereIn('status', ['queued', 'processing', 'pending_oms', 'oms_visible_provisional'])
            ->exists();
        if (! $outstanding) {
            $exceptions = DB::table('shipout_production_batch_items')
                ->where('batch_id', $this->batchId)
                ->whereIn('status', ['skipped', 'uncertain', 'failed', 'provider_cancelled', 'needs_review'])
                ->exists();
            DB::table('shipout_production_batches')
                ->where('id', $this->batchId)
                ->whereIn('status', ['running', 'awaiting_oms'])
                ->update([
                    'status' => $exceptions ? 'completed_partial' : 'completed',
                    'finished_at' => now(), 'updated_at' => now(),
                ]);
        }
    }

    public function failed(Throwable $error): void
    {
        DB::table('shipout_production_batch_items')
            ->where('batch_id', $this->batchId)
            ->where('order_id', $this->orderId)
            ->whereIn('status', ['pending_oms', 'oms_visible_provisional'])
            ->update(['status' => 'needs_review', 'result_code' => 'OMS_READBACK_JOB_FAILED_MANUAL_CHECK', 'updated_at' => now()]);
        $this->completeBatchIfReconciled();
    }
}
