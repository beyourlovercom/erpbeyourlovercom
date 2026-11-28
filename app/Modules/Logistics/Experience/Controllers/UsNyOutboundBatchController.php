<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Logistics\Application\Xlwms\UsNyOutboundBatch;
use App\Modules\Logistics\Experience\Jobs\ProcessUsNyOutboundBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class UsNyOutboundBatchController
{
    private function selection(Request $r): ?array
    {
        $raw = $r->input('order_ids') ?? '';
        if ($r->input('scope') === 'all' && $raw === '') {
            return null;
        }
        if ($r->input('scope') !== 'selected' || ! is_string($raw) || ! preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/D', $raw)) {
            throw new RuntimeException('US_NY_BATCH_SELECTION_INVALID');
        }
        $ids = array_map('intval', explode(',', $raw));
        if (count($ids) > UsNyOutboundBatch::MAX || count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('US_NY_BATCH_SELECTION_INVALID');
        }
        sort($ids);

        return $ids;
    }

    public function preview(Request $r, UsNyOutboundBatch $svc): JsonResponse
    {
        abort_unless(UsNyOutboundBatch::visible(), 403);
        try {
            return response()->json($svc->preview($this->selection($r)))->header('Cache-Control', 'no-store, private');
        } catch (RuntimeException $e) {
            return response()->json(['error_code' => $e->getMessage()], 422);
        }
    }

    public function start(Request $r, UsNyOutboundBatch $svc): JsonResponse
    {
        abort_unless(UsNyOutboundBatch::visible() && (app()->environment('local', 'testing') || UsNyOutboundBatch::liveEnabled()), 403);
        $i = $r->validate(['fingerprint' => ['required', 'regex:/^[0-9a-f]{64}$/D'], 'confirmation' => ['required', 'string', 'max:80']]);
        try {
            $result = $svc->start((int) $r->user()->id, $this->selection($r), $i['fingerprint'], $i['confirmation']);
            if ($result['dry_run']) {
                try {
                    ProcessUsNyOutboundBatch::dispatch($result['batch_id']);
                } catch (\Throwable) {
                    DB::table('xlwms_ny_dispatch_batches')->where('id', $result['batch_id'])->update(['status' => 'paused', 'updated_at' => now()]);

                    return response()->json(['error_code' => 'US_NY_QUEUE_UNAVAILABLE'], 503);
                }
            }

            return response()->json($result, 202)->header('Cache-Control', 'no-store, private');
        } catch (RuntimeException $e) {
            return response()->json(['error_code' => $e->getMessage()], 409);
        }
    }

    public function status(string $batchId, Request $r, UsNyOutboundBatch $svc): JsonResponse
    {
        abort_unless(UsNyOutboundBatch::visible(), 403);
        abort_unless(DB::table('xlwms_ny_dispatch_batches')->where('id', $batchId)->where('operator_user_id', $r->user()->id)->exists(), 404);

        return response()->json($svc->status($batchId))->header('Cache-Control','no-store, private');
    }
}
