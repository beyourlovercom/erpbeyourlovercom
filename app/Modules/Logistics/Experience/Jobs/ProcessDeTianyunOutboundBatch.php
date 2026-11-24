<?php

namespace App\Modules\Logistics\Experience\Jobs;

use App\Models\User;
use App\Modules\Logistics\Application\LogisticsExecutionAccess;
use App\Modules\Logistics\Application\Xlwms\DeTianyunOutboundBatchService;
use App\Modules\Logistics\Application\Xlwms\DeTianyunLiveOrderCreateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * DE's MorePlus-style durable one-by-one queue. Until a separate real-CREATE
 * authorization this job NEVER imports an OMS HTTP gateway or makes a POST.
 */
final class ProcessDeTianyunOutboundBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const QUEUE = 'xlwms-de-batch';

    public int $tries = 1;

    public int $timeout = 55;

    public function __construct(public readonly string $batchId) {}

    public function handle(DeTianyunOutboundBatchService $service, LogisticsExecutionAccess $access, ?DeTianyunLiveOrderCreateService $live = null): void
    {
        $lock = Cache::lock('xlwms:de:batch:worker', 55);
        if (! $lock->get()) {
            // A non-retried worker must never leave a newly queued batch stranded.
            DB::table('xlwms_de_dispatch_batches')->where('id', $this->batchId)
                ->where('status', 'queued')
                ->update(['status' => 'paused', 'updated_at' => now()]);

            return;
        }

        try {
            $batch = DB::table('xlwms_de_dispatch_batches')->where('id', $this->batchId)->first();
            if (! $batch || ! in_array($batch->status, ['queued', 'running'], true)) {
                return;
            }
            $actor = User::query()->find($batch->operator_user_id);
            $dev = app()->environment('local', 'testing') && (bool) $batch->dry_run;
            $production = app()->environment('production') && ! (bool) $batch->dry_run
                && (bool) config('xlwms.de_order_batch_read_enabled', false)
                && (bool) config('xlwms.de_order_batch_live_enabled', false);
            if (! ($dev || $production) || ! $access->allows($actor)) {
                $this->pause('DE_BATCH_DEV_OPERATOR_NOT_AUTHORIZED');

                return;
            }
            DB::table('xlwms_de_dispatch_batches')->where('id', $this->batchId)
                ->update(['status' => 'running', 'updated_at' => now()]);
            $items = DB::table('xlwms_de_dispatch_batch_items')->where('batch_id', $this->batchId)
                ->where('status', 'queued')->orderBy('id')->get();
            foreach ($items as $item) {
                $claimed = DB::table('xlwms_de_dispatch_batch_items')
                    ->where('id', $item->id)->where('status', 'queued')
                    ->update(['status' => 'processing', 'updated_at' => now()]);
                if ($claimed !== 1) {
                    continue;
                }
                $status = 'skipped';
                $code = 'DE_PREFLIGHT_CHANGED';
                try {
                    if ($production) {
                        // No blind retries. The durable XlwmsDispatchAttempt
                        // ledger is written before each real OMS CREATE.
                        $created = ($live ?? app(DeTianyunLiveOrderCreateService::class))
                            ->create((int) $item->order_id, (string) $item->payload_hash, (int) $actor->id);
                        $status = $created['status'];
                        $code = $created['code'];
                    } else {
                        // Dev simulation is provider-I/O-free.
                        $fresh = $service->preview([(int) $item->order_id]);
                        $candidate = $fresh['items'][0] ?? [];
                        if (($candidate['ready'] ?? false) === true
                            && is_string($candidate['payload_hash'] ?? null)
                            && hash_equals((string) $item->payload_hash, $candidate['payload_hash'])) {
                            $status = 'simulated';
                            $code = 'DEV_SIMULATED_NO_PROVIDER_IO';
                        }
                    }
                } catch (Throwable) {
                    $status = 'skipped';
                    $code = $production ? 'DE_PRODUCTION_CREATE_GATE_OR_PREVIEW_BLOCKED'
                        : 'DE_PREFLIGHT_FAILED';
                }
                DB::table('xlwms_de_dispatch_batch_items')->where('id', $item->id)
                    ->update(['status' => $status, 'result_code' => $code, 'updated_at' => now()]);
            }
            $failed = DB::table('xlwms_de_dispatch_batch_items')->where('batch_id', $this->batchId)
                ->whereIn('status', ['queued', 'processing', 'skipped', 'uncertain'])->exists();
            DB::table('xlwms_de_dispatch_batches')->where('id', $this->batchId)
                ->update(['status' => $failed ? 'completed_partial' : 'completed',
                    'finished_at' => now(), 'updated_at' => now()]);
        } finally {
            $lock->release();
        }
    }

    private function pause(string $code): void
    {
        DB::table('xlwms_de_dispatch_batch_items')
            ->where('batch_id', $this->batchId)->where('status', 'processing')
            ->update(['status' => 'uncertain', 'result_code' => $code, 'updated_at' => now()]);
        DB::table('xlwms_de_dispatch_batches')->where('id', $this->batchId)
            ->update(['status' => 'paused', 'updated_at' => now()]);
    }

    public function failed(Throwable $error): void
    {
        $this->pause('DE_BATCH_WORKER_INTERRUPTED');
    }
}
