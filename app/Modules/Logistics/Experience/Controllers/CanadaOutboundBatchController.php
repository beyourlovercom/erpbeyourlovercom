<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Logistics\Application\Wlwy\CanadaOutboundBatch;
use App\Modules\Logistics\Experience\Jobs\ProcessCanadaOutboundBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CanadaOutboundBatchController
{
    public static function visible(): bool
    {
        return app()->environment('local', 'testing')
            || (app()->environment('production') && config('wlwy.ca_batch_read_enabled'));
    }

    public function preview(Request $request, CanadaOutboundBatch $batch): JsonResponse
    {
        abort_unless(self::visible(), 403);
        try {
            return response()->json($batch->preview($this->selection($request)))->header('Cache-Control', 'no-store, private');
        } catch (RuntimeException $e) {
            return response()->json(['error_code' => $e->getMessage()], 422);
        }
    }

    public function start(Request $request, CanadaOutboundBatch $batch): JsonResponse
    {
        abort_unless(self::visible(), 403);
        abort_unless(app()->environment('local', 'testing') || (config('wlwy.ca_dispatch_enabled') && config('wlwy.ca_dispatch_owner_approved')), 403);
        $input = $request->validate(['fingerprint' => ['required', 'regex:/^[a-f0-9]{64}$/D'], 'confirmation' => ['required', 'string', 'max:80']]);
        try {
            $started = $batch->start((int) $request->user()->id, $input['fingerprint'], $this->selection($request), $input['confirmation']);
            if ($started['dry_run']) {
                try {
                    ProcessCanadaOutboundBatch::dispatch($started['batch_id']);
                } catch (\Throwable) {
                    DB::table('wlwy_ca_batches')->where('id', $started['batch_id'])->where('status', 'queued')
                        ->update(['status' => 'paused', 'updated_at' => now()]);

                    return response()->json(['error_code' => 'CA_DEV_QUEUE_UNAVAILABLE'], 503);
                }
            }

            return response()->json($started, 202)
                ->header('Cache-Control', 'no-store, private');
        } catch (RuntimeException $e) {
            return response()->json(['error_code' => $e->getMessage()], 409);
        }
    }

    public function status(string $batchId, Request $request, CanadaOutboundBatch $batch): JsonResponse
    {
        abort_unless(self::visible(), 403);
        abort_unless(DB::table('wlwy_ca_batches')->where('id', $batchId)->where('operator_user_id', $request->user()->id)->exists(), 404);

        return response()->json($batch->status($batchId))->header('Cache-Control', 'no-store, private');
    }

    private function selection(Request $request): ?array
    {
        $raw = $request->input('order_ids') ?? '';
        if ($request->input('scope') === 'all' && $raw === '') {
            return null;
        }
        if ($request->input('scope') !== 'selected' || ! is_string($raw) || ! preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/D', $raw)) {
            throw new RuntimeException('CA_BATCH_SELECTION_INVALID');
        }
        $ids = array_map('intval', explode(',', $raw));
        if (count($ids) > CanadaOutboundBatch::MAX || count($ids) !== count(array_unique($ids))) {
            throw new RuntimeException('CA_BATCH_SELECTION_INVALID');
        }
        sort($ids);

        return $ids;
    }
}
