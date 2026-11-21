<?php

namespace App\Modules\Sync\Experience\Controllers;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Sync\Experience\Jobs\RunDeTianyunWarehouseSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * R4: only DE/Hannover can initiate an isolated Dev manual inventory refresh.
 * No order CREATE, no Product creation, and no Production inventory writes.
 */
final class DeTianyunWarehouseSyncController
{
    public function start(Request $request, Warehouse $warehouse): JsonResponse
    {
        abort_unless(app()->environment('local', 'testing')
            && (bool) config('xlwms.de_manual_sync_enabled', false), 403);
        abort_unless($warehouse->code === 'DE' && $warehouse->is_active, 404);

        $connectionReady = DB::table('warehouse_openapi_connections')
            ->where('warehouse_id', $warehouse->id)
            ->where('provider', 'xlwms')
            ->where('oms_warehouse_code', 'Hannover')
            ->whereNotNull('app_key_encrypted')
            ->whereNotNull('app_secret_encrypted')
            ->exists();
        if (! $connectionReady) {
            return $this->error('XLWMS_DE_CONNECTOR_NOT_CONFIGURED', 409);
        }

        $lock = Cache::lock('xlwms:de:manual-sync:start', 15);
        if (! $lock->get()) {
            return $this->error('XLWMS_DE_SYNC_LOCKED', 409);
        }
        try {
            $this->expireStale($warehouse);
            if (DB::table('xlwms_inventory_manual_runs')
                ->where('warehouse_id', $warehouse->id)
                ->whereIn('status', ['queued', 'running'])->exists()) {
                return $this->error('XLWMS_DE_SYNC_ALREADY_RUNNING', 409);
            }

            $apply = (bool) config('xlwms.de_inventory_dev_apply_enabled', false)
                && (bool) config('xlwms.inventory_apply_enabled', false);
            $runId = DB::table('xlwms_inventory_manual_runs')->insertGetId([
                'warehouse_id' => $warehouse->id,
                'operator_user_id' => (int) $request->user()->id,
                'status' => 'queued',
                'apply_requested' => $apply,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            try {
                RunDeTianyunWarehouseSync::dispatch($runId)->onQueue('default');
            } catch (Throwable) {
                DB::table('xlwms_inventory_manual_runs')->where('id', $runId)->update([
                    'status' => 'failed', 'error_code' => 'XLWMS_DE_SYNC_QUEUE_UNAVAILABLE',
                    'finished_at' => now(), 'updated_at' => now(),
                ]);

                return $this->error('XLWMS_DE_SYNC_QUEUE_UNAVAILABLE', 503);
            }

            return response()->json([
                'run_id' => $runId, 'status' => 'queued',
                'mode' => $apply ? 'apply_if_verified' : 'read_only',
                'erp_stock_updated' => false,
            ], 202)->header('Cache-Control', 'no-store, private');
        } finally {
            $lock->release();
        }
    }

    public function status(Warehouse $warehouse, int $runId): JsonResponse
    {
        abort_unless($warehouse->code === 'DE', 404);
        if (app()->environment('local', 'testing')) {
            $this->expireStale($warehouse);
        }
        $run = DB::table('xlwms_inventory_manual_runs')
            ->where('id', $runId)->where('warehouse_id', $warehouse->id)->first();
        abort_unless($run, 404);

        return response()->json([
            'run_id' => (int) $run->id,
            'status' => $run->status,
            'mode' => $run->apply_requested ? 'apply_if_verified' : 'read_only',
            'erp_stock_updated' => in_array($run->status, ['applied', 'partial_applied'], true),
            'provider_sku_count' => (int) $run->provider_sku_count,
            'mapped_sku_count' => (int) $run->mapped_sku_count,
            'unmatched_sku_count' => (int) $run->unmatched_sku_count,
            'blocked_sku_count' => (int) $run->blocked_sku_count,
            'error_code' => $run->error_code,
            'finished_at' => $run->finished_at,
        ])->header('Cache-Control', 'no-store, private');
    }

    private function expireStale(Warehouse $warehouse): void
    {
        // A stopped worker must not leave the manual button blocked forever.
        // Late queue deliveries are ignored because jobs only process queued runs.
        DB::table('xlwms_inventory_manual_runs')->where('warehouse_id', $warehouse->id)
            ->whereIn('status', ['queued', 'running'])
            ->where('updated_at', '<', now()->subMinutes(5))
            ->update([
                'status' => 'failed', 'error_code' => 'XLWMS_DE_SYNC_TIMEOUT',
                'finished_at' => now(), 'updated_at' => now(),
            ]);
    }

    private function error(string $code, int $httpStatus): JsonResponse
    {
        return response()->json(['error_code' => $code], $httpStatus)
            ->header('Cache-Control', 'no-store, private');
    }
}
