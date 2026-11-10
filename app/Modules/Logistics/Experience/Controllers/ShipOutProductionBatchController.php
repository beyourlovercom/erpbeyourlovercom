<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Logistics\Application\ShipOut\ShipOutProductionBatchService;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionV1OperatorAccess;
use App\Modules\Logistics\Experience\Jobs\ProcessShipOutProductionBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Dedicated opt-in batch controller; production is default OFF. */
final class ShipOutProductionBatchController
{
    public function preview(
        Request $request, ShipOutProductionBatchService $batches,
        ShipOutProductionV1OperatorAccess $access,
    ): JsonResponse {
        $this->authorize($request, $access);

        return response()->json($batches->preview())->header('Cache-Control', 'no-store, private');
    }

    public function start(
        Request $request, ShipOutProductionBatchService $batches,
        ShipOutProductionV1OperatorAccess $access,
    ): JsonResponse {
        $this->authorize($request, $access);
        $input = $request->validate([
            'fingerprint' => ['required', 'regex:/^[0-9a-f]{64}$/D'],
            'confirmation' => ['required', 'string', 'max:80'],
        ]);
        $preview = $batches->preview();
        if (! hash_equals('CONFIRM_MOREPLUS_BATCH_'.$preview['count'], $input['confirmation'])) {
            return $this->blocked('SHIPOUT_BATCH_CONFIRMATION_REQUIRED', 422);
        }
        try {
            if (app()->environment('production') && in_array(config('queue.default'), ['sync', 'null'], true)) {
                return $this->blocked('SHIPOUT_BATCH_ASYNC_QUEUE_REQUIRED', 503);
            }
            $id = $batches->start((int) $request->user()->id, $input['fingerprint']);
            try {
                ProcessShipOutProductionBatch::dispatch($id)->onQueue(ProcessShipOutProductionBatch::QUEUE);
            } catch (Throwable) {
                DB::table('shipout_production_batches')->where('id', $id)
                    ->update(['status' => 'paused', 'updated_at' => now()]);

                return $this->blocked('SHIPOUT_BATCH_QUEUE_UNAVAILABLE', 503);
            }

            return response()->json([
                'batch_id' => $id, 'status' => 'queued', 'dry_run' => $preview['dry_run'],
                'total' => $preview['count'], 'tracking_apply' => false,
                'erp_shipment_unchanged' => true, 'woo_unchanged' => true,
            ], 202)->header('Cache-Control', 'no-store, private');
        } catch (RuntimeException $error) {
            return $this->blocked($error->getMessage());
        }
    }

    public function status(
        string $batchId, Request $request, ShipOutProductionBatchService $batches,
        ShipOutProductionV1OperatorAccess $access,
    ): JsonResponse {
        $this->authorize($request, $access);
        try {
            $batch = DB::table('shipout_production_batches')->where('id', $batchId)->first();
            abort_unless($batch && (int) $batch->operator_user_id === (int) $request->user()->id, 404);

            return response()->json($batches->status($batchId))
                ->header('Cache-Control', 'no-store, private');
        } catch (RuntimeException) {
            return $this->blocked('SHIPOUT_BATCH_NOT_FOUND', 404);
        }
    }

    private function authorize(Request $request, ShipOutProductionV1OperatorAccess $access): void
    {
        $dev = app()->environment(['local', 'testing']);
        abort_unless(
            $dev
                ? $request->user()?->can('logistics.view')
                : (app()->environment('production')
                    && (bool) config('shipout.production_v1_batch_enabled', false)
                    && (bool) config('shipout.production_v1_batch_owner_approved', false)
                    && (bool) config('shipout.production_v1_dispatch_enabled', false)
                    && (bool) config('shipout.production_v1_owner_approved', false)
                    && $access->allows($request->user())),
            403,
        );
    }

    private function blocked(string $code, int $status = 409): JsonResponse
    {
        return response()->json([
            'error_code' => $code, 'retry_same_order' => false,
            'erp_shipment_unchanged' => true, 'woo_unchanged' => true,
        ], $status)->header('Cache-Control', 'no-store, private');
    }
}
