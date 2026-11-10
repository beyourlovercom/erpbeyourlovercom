<?php

namespace App\Modules\Logistics\Experience\Jobs;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionBatchService;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionCanaryDispatchService;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionV1OperatorAccess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * One order per job. Next order is scheduled after a conservative 70-second
 * gap, and the durable one-shot ledger remains the sole authority for retries.
 */
final class ProcessShipOutProductionBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const QUEUE = 'shipout-production-batch';

    public int $tries = 1;

    public int $timeout = 55;

    public function __construct(public readonly string $batchId) {}

    public function handle(
        ShipOutProductionCanaryDispatchService $dispatch,
        ShipOutProductionV1OperatorAccess $access,
    ): void {
        $mutex = Cache::lock('shipout:production:batch:worker', 55);
        if (! $mutex->get()) {
            // Never recurse in sync/test mode; a contention is a recoverable
            // operational pause, not permission to issue a second POST.
            if (config('queue.default') !== 'sync') {
                self::dispatch($this->batchId)->onQueue(self::QUEUE)->delay(now()->addSeconds(70));
            }

            return;
        }
        try {
            $batch = DB::table('shipout_production_batches')->where('id', $this->batchId)->first();
            if (! $batch || ! in_array($batch->status, ['queued', 'running'], true)) {
                return;
            }
            $item = DB::table('shipout_production_batch_items')->where('batch_id', $this->batchId)
                ->where('status', 'queued')->orderBy('id')->first();
            if (! $item) {
                $this->finish();

                return;
            }
            $claimed = DB::table('shipout_production_batch_items')->where('id', $item->id)
                ->where('status', 'queued')->update(['status' => 'processing', 'updated_at' => now()]);
            if ($claimed !== 1) {
                return;
            }
            DB::table('shipout_production_batches')->where('id', $this->batchId)
                ->update(['status' => 'running', 'updated_at' => now()]);

            $resultStatus = 'skipped';
            $code = null;
            $providerId = null;
            try {
                $actor = User::query()->find($batch->operator_user_id);
                if ($batch->dry_run) {
                    if (app()->environment('production')
                        || ! $actor?->is_active || ! $actor->hasPermission('logistics.view')) {
                        throw new RuntimeException('SHIPOUT_BATCH_DRY_RUN_NOT_AUTHORIZED');
                    }
                    // DEV does not contact ShipOut: no GET, no POST, no test order creation.
                    $resultStatus = 'simulated';
                    $code = 'DEV_SIMULATED_NO_PROVIDER_IO';
                } else {
                    if (! $access->allows($actor)) {
                        throw new RuntimeException('SHIPOUT_V1_OPERATOR_DENIED');
                    }
                    // Re-check exact order scope at execution time. This blocks
                    // queued jobs after a Canary id change or a kill-switch flip.
                    if (! in_array((int) $item->order_id,
                        ShipOutProductionBatchService::productionApprovedOrderIds(),
                        true)) {
                        throw new RuntimeException('SHIPOUT_BATCH_CANARY_SCOPE_DENIED');
                    }
                    if (! app()->environment('production')
                        || ! (bool) config('shipout.production_v1_batch_enabled', false)
                        || ! (bool) config('shipout.production_v1_batch_owner_approved', false)
                        || ! (bool) config('shipout.production_v1_dispatch_enabled', false)
                        || ! (bool) config('shipout.production_v1_owner_approved', false)) {
                        throw new RuntimeException('SHIPOUT_BATCH_NOT_AUTHORIZED');
                    }
                    $date = now('America/Los_Angeles')->addDay()->format('Y-m-d');
                    $preview = $dispatch->previewApproved((int) $item->order_id, $date);
                    $reply = $dispatch->dispatchApprovedOrder(
                        (int) $item->order_id, $date, $preview['payload_hash'],
                        'SUBMIT_SHIPOUT_ORDER_'.$item->order_id, $actor,
                    );
                    $providerId = $reply['provider_order_id'] ?? null;
                    // A ShipOut async POST ACK is not an OMS accepted order.
                    // Never display a final success without independent signed GET.
                    $resultStatus = ($reply['status'] ?? '') === 'acknowledged' ? 'pending_oms' : 'failed';
                    $code = $reply['result_code'] ?? 'PROVIDER_UNCONFIRMED';
                }
            } catch (Throwable $error) {
                $message = $error instanceof RuntimeException ? $error->getMessage() : '';
                $code = preg_match('/^[A-Z0-9_]{5,100}$/D', $message)
                    ? $message : 'SHIPOUT_BATCH_PROCESS_ERROR';
                // Any ambiguous outcome must NEVER be resubmitted.
                $resultStatus = DB::table('shipout_dispatch_attempts')
                    ->where('order_id', $item->order_id)->exists() ? 'uncertain' : 'skipped';
            }
            DB::table('shipout_production_batch_items')->where('id', $item->id)
                ->update([
                    'status' => $resultStatus, 'result_code' => $code,
                    'provider_order_id' => $providerId, 'updated_at' => now(),
                ]);
            if ($resultStatus === 'pending_oms') {
                // Read-only delayed reconciliation. No second POST is possible:
                // this job only receives an existing immutable attempt ID.
                try {
                    VerifyShipOutProductionBatchItem::dispatch($this->batchId, (int) $item->order_id)
                        ->onQueue(self::QUEUE)->delay(now()->addSeconds(20));
                } catch (Throwable) {
                    DB::table('shipout_production_batch_items')
                        ->where('id', $item->id)->where('status', 'pending_oms')
                        ->update(['status' => 'needs_review', 'result_code' => 'OMS_VERIFY_QUEUE_UNAVAILABLE_MANUAL_CHECK', 'updated_at' => now()]);
                }
            }
            if ($batch->dry_run && ! app()->environment('production')) {
                // Sync queues in local tests do not respect delays. Complete the
                // remaining synthetic items without recursion or external I/O.
                DB::table('shipout_production_batch_items')
                    ->where('batch_id', $this->batchId)->where('status', 'queued')
                    ->update(['status' => 'simulated', 'result_code' => 'DEV_SIMULATED_NO_PROVIDER_IO', 'updated_at' => now()]);
                $this->finish();
            } elseif (DB::table('shipout_production_batch_items')->where('batch_id', $this->batchId)
                ->where('status', 'queued')->exists()) {
                self::dispatch($this->batchId)->onQueue(self::QUEUE)->delay(now()->addSeconds(70));
            } else {
                $awaitingOms = DB::table('shipout_production_batch_items')
                    ->where('batch_id', $this->batchId)
                    ->whereIn('status', ['pending_oms', 'oms_visible_provisional'])
                    ->exists();
                if ($awaitingOms) {
                    DB::table('shipout_production_batches')->where('id', $this->batchId)
                        ->update(['status' => 'awaiting_oms', 'updated_at' => now()]);
                } else {
                    $this->finish();
                }
            }
        } finally {
            $mutex->release();
        }
    }

    private function finish(): void
    {
        $exceptions = DB::table('shipout_production_batch_items')
            ->where('batch_id', $this->batchId)
            ->whereIn('status', ['skipped', 'uncertain', 'failed', 'provider_cancelled', 'needs_review'])
            ->exists();
        DB::table('shipout_production_batches')->where('id', $this->batchId)
            ->update([
                'status' => $exceptions ? 'completed_partial' : 'completed',
                'finished_at' => now(), 'updated_at' => now(),
            ]);
    }

    public function failed(Throwable $error): void
    {
        // A worker crash isolates the one in-progress order, even if a POST
        // reached ShipOut. Never requeue that order or reset its attempt ledger.
        DB::table('shipout_production_batch_items')
            ->where('batch_id', $this->batchId)->where('status', 'processing')
            ->update(['status' => 'uncertain', 'result_code' => 'WORKER_INTERRUPTED_MANUAL_CHECK', 'updated_at' => now()]);

        try {
            $remaining = DB::table('shipout_production_batch_items')
                ->where('batch_id', $this->batchId)->where('status', 'queued')->exists();
            if ($remaining) {
                if (config('queue.default') === 'sync'
                    || ! (bool) config('shipout.production_v1_batch_enabled', false)
                    || ! (bool) config('shipout.production_v1_batch_owner_approved', false)) {
                    DB::table('shipout_production_batches')->where('id', $this->batchId)
                        ->update(['status' => 'paused', 'updated_at' => now()]);

                    return;
                }
                self::dispatch($this->batchId)->onQueue(self::QUEUE)->delay(now()->addSeconds(70));

                return;
            }
            $pendingReads = DB::table('shipout_production_batch_items')
                ->where('batch_id', $this->batchId)
                ->whereIn('status', ['pending_oms', 'oms_visible_provisional'])->exists();
            if ($pendingReads) {
                DB::table('shipout_production_batches')->where('id', $this->batchId)
                    ->update(['status' => 'awaiting_oms', 'updated_at' => now()]);
            } else {
                $this->finish();
            }
        } catch (Throwable) {
            // Shared queue or DB unavailable: stop, do not risk duplicate jobs.
            DB::table('shipout_production_batches')->where('id', $this->batchId)
                ->update(['status' => 'paused', 'updated_at' => now()]);
        }
    }
}
