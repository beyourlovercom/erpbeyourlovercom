<?php

namespace App\Modules\Logistics\Experience\Jobs;

use App\Modules\Logistics\Application\Xlwms\UsNyOutboundBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ProcessUsNyOutboundBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 55;

    public function __construct(public readonly string $batchId) {}

    public function handle(UsNyOutboundBatch $svc): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }
        if (($svc->drain(200, $this->batchId)['busy'] ?? false)) {
            $this->pause();
        }
    }

    public function failed(Throwable $e): void
    {
        $this->pause();
    }

    private function pause(): void
    {
        DB::table('xlwms_ny_dispatch_batches')->where('id', $this->batchId)->where('dry_run', true)->whereIn('status', ['queued', 'running'])->update(['status' => 'paused', 'updated_at' => now()]);
    }
}
