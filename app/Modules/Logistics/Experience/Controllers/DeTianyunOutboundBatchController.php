<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Logistics\Application\Xlwms\DeTianyunOutboundBatchService;
use App\Modules\Logistics\Experience\Jobs\ProcessDeTianyunOutboundBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** DE-only selected order workflow. Live OMS CREATE is not implemented here. */
final class DeTianyunOutboundBatchController
{
    public function preview(Request $request, DeTianyunOutboundBatchService $batches): JsonResponse
    {
        abort_unless($this->canPreview(), 403);
        try {
            return response()->json($batches->preview($this->selection($request)))
                ->header('Cache-Control', 'no-store, private');
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function start(Request $request, DeTianyunOutboundBatchService $batches): JsonResponse
    {
        abort_unless($this->canStart(), 403);
        $input = $request->validate([
            'fingerprint' => ['required', 'regex:/^[0-9a-f]{64}$/D'],
            'confirmation' => ['required', 'string', 'max:80'],
        ]);
        try {
            $selected = $this->selection($request);
            $preview = $batches->preview($selected);
            if (! hash_equals('CONFIRM_DE_TIANYUN_BATCH_'.$preview['count'], $input['confirmation'])) {
                return $this->error('DE_BATCH_CONFIRMATION_REQUIRED', 422);
            }
            $id = $batches->start((int) $request->user()->id, $input['fingerprint'], $selected);
            try {
                ProcessDeTianyunOutboundBatch::dispatch($id)
                    ->onQueue(app()->environment('production') ? 'default' : ProcessDeTianyunOutboundBatch::QUEUE);
            } catch (Throwable) {
                DB::table('xlwms_de_dispatch_batches')->where('id', $id)
                    ->update(['status' => 'paused', 'updated_at' => now()]);

                return $this->error('DE_BATCH_QUEUE_UNAVAILABLE', 503);
            }

            return response()->json([
                'batch_id' => $id, 'status' => 'queued', 'dry_run' => ! app()->environment('production'),
                'total' => $preview['count'], 'erp_shipment_unchanged' => true,
                'woo_unchanged' => true, 'provider_io' => app()->environment('production'),
            ], 202)->header('Cache-Control', 'no-store, private');
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage());
        }
    }

    public function status(string $batchId, Request $request, DeTianyunOutboundBatchService $batches): JsonResponse
    {
        abort_unless($this->canPreview(), 403);
        $batch = DB::table('xlwms_de_dispatch_batches')->where('id', $batchId)->first();
        abort_unless($batch && (int) $batch->operator_user_id === (int) $request->user()->id, 404);

        return response()->json($batches->status($batchId))
            ->header('Cache-Control', 'no-store, private');
    }

    private function canPreview(): bool
    {
        return app()->environment('local', 'testing')
            || (app()->environment('production')
                && (bool) config('xlwms.de_order_batch_read_enabled', false));
    }

    private function canStart(): bool
    {
        return app()->environment('local', 'testing')
            || ($this->canPreview() && app()->environment('production')
                && (bool) config('xlwms.de_order_batch_live_enabled', false));
    }

    /** @return list<int> */
    private function selection(Request $request): array
    {
        $raw = $request->input('order_ids', '') ?? '';
        if ($request->input('scope') !== 'selected' || ! is_string($raw)
            || preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/D', $raw) !== 1) {
            throw new RuntimeException('DE_BATCH_SELECTION_INVALID');
        }
        $ids = array_map('intval', explode(',', $raw));
        if ($ids === [] || count($ids) > DeTianyunOutboundBatchService::MAX_ORDERS
            || count(array_unique($ids)) !== count($ids)) {
            throw new RuntimeException('DE_BATCH_SELECTION_INVALID');
        }

        return $ids;
    }

    private function error(string $code, int $status = 409): JsonResponse
    {
        return response()->json([
            'error_code' => $code, 'retry_same_order' => false,
            'erp_shipment_unchanged' => true, 'woo_unchanged' => true,
        ], $status)->header('Cache-Control', 'no-store, private');
    }
}
