<?php

namespace App\Modules\Logistics\Experience\Jobs;

use App\Modules\Logistics\Application\Wlwy\CanadaOutboundBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Dev simulation only. Production uses the bounded scheduler drain. */
final class ProcessCanadaOutboundBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 55;

    public function __construct(public readonly string $batchId) {}

    public function handle(CanadaOutboundBatch $batch): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }
        $result = $batch->drain(CanadaOutboundBatch::MAX, $this->batchId);
        if ($result['busy'] ?? false) {
            $this->pause();
        }
    }

    public function failed(Throwable $error): void
    {
        $this->pause();
    }

    private function pause(): void
    {
        DB::table('wlwy_ca_batches')->where('id', $this->batchId)->where('dry_run', true)
            ->whereIn('status', ['queued', 'running'])->update(['status' => 'paused', 'updated_at' => now()]);
    }
}
