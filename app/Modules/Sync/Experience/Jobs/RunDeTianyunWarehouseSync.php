<?php

namespace App\Modules\Sync\Experience\Jobs;

use App\Models\User;
use App\Modules\Sync\Application\DeTianyunInventoryRunReporter;
use App\Modules\Logistics\Application\Xlwms\XlwmsInventorySyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** DE inventory only; never posts an OMS outbound order. */
final class RunDeTianyunWarehouseSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 115;

    public function __construct(public readonly int $runId) {}

    public function handle(XlwmsInventorySyncService $service, DeTianyunInventoryRunReporter $reporter): void
    {
        $lock = Cache::lock('xlwms:de:manual-sync:worker', 120);
        if (! $lock->get()) {
            $this->markFailed('XLWMS_DE_SYNC_WORKER_BUSY');

            return;
        }
        try {
            $run = DB::table('xlwms_inventory_manual_runs')->where('id', $this->runId)->first();
            if (! $run || $run->status !== 'queued') {
                return;
            }
            $actor = User::query()->find($run->operator_user_id);
            $dev = app()->environment('local', 'testing')
                && (bool) config('xlwms.de_manual_sync_enabled', false);
            $prodRead = app()->environment('production')
                && (bool) config('xlwms.de_production_read_enabled', false);
            if (! ($dev || $prodRead)
                || ! $actor?->is_active || ! $actor->can('sync.operate')) {
                $this->markFailed('XLWMS_DE_SYNC_NOT_AUTHORIZED');

                return;
            }
            // A Production web task must never apply stock, even if a legacy
            // or tampered queue row claims apply_requested=true.
            if ($run->apply_requested && (! $dev
                || ! (bool) config('xlwms.de_inventory_dev_apply_enabled', false)
                || ! (bool) config('xlwms.inventory_apply_enabled', false))) {
                $this->markFailed('XLWMS_DE_SYNC_APPLY_GATES_CHANGED');

                return;
            }

            $claimed = DB::table('xlwms_inventory_manual_runs')->where('id', $this->runId)
                ->where('status', 'queued')
                ->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);
            if ($claimed !== 1) {
                return; // Another worker or stale-task cleanup already resolved this run.
            }
            try {
                $resumeVerifiedSkus = $run->resume_from_run_id === null ? []
                    : DB::table('xlwms_inventory_manual_run_rows')
                        ->where('run_id', $run->resume_from_run_id)
                        ->where('write_verified', true)
                        ->whereIn('outcome', ['updated', 'unchanged'])
                        ->pluck('provider_sku')->all();
                $result = $service->sync((bool) $run->apply_requested, 'DE', $resumeVerifiedSkus);
                $stats = $reporter->record($this->runId, $result);
                $state = (string) ($result['status'] ?? 'failed');
                if (! in_array($state, ['read_only', 'blocked', 'not_ready', 'applied', 'partial_applied'], true)) {
                    $state = 'failed';
                }
                DB::table('xlwms_inventory_manual_runs')->where('id', $this->runId)->update([
                    'status' => $state,
                    'snapshot_id' => $result['snapshot_id'] ?? null,
                    'provider_sku_count' => (int) ($result['provider_sku_count'] ?? 0),
                    'mapped_sku_count' => (int) ($result['mapped_sku_count'] ?? 0),
                    'unmatched_sku_count' => (int) ($result['unmatched_sku_count'] ?? 0),
                    'blocked_sku_count' => (int) ($result['blocked_sku_count'] ?? 0),
                    'error_code' => $state === 'failed' ? 'XLWMS_DE_SYNC_UNEXPECTED_RESULT' : null,
                    'outcome' => $stats['outcome'],
                    'finished_at' => now(), 'updated_at' => now(),
                ]);
            } catch (Throwable) {
                // Provider exceptions can contain signed payloads or secret metadata.
                // If stock changed but evidence logging failed, do not imply an OMS failure.
                $code = isset($result) ? 'XLWMS_DE_SYNC_EVIDENCE_UNAVAILABLE' : 'XLWMS_DE_SYNC_PROVIDER_FAILED';
                $this->markFailed($code);
            }
        } finally {
            $lock->release();
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->markFailed('XLWMS_DE_SYNC_WORKER_INTERRUPTED');
    }

    private function markFailed(string $code): void
    {
        DB::table('xlwms_inventory_manual_runs')->where('id', $this->runId)
            ->whereIn('status', ['queued', 'running'])->update([
                'status' => $code === 'XLWMS_DE_SYNC_EVIDENCE_UNAVAILABLE' ? 'not_ready' : 'failed',
                'outcome' => $code === 'XLWMS_DE_SYNC_EVIDENCE_UNAVAILABLE' ? 'needs_reconcile' : 'failed',
                'error_code' => $code,
                'finished_at' => now(), 'updated_at' => now(),
            ]);
    }
}
