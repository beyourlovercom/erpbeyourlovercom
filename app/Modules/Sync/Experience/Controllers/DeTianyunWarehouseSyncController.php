<?php

namespace App\Modules\Sync\Experience\Controllers;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Sync\Experience\Jobs\RunDeTianyunWarehouseSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * R4: only DE/Hannover can initiate an isolated Dev manual inventory refresh.
 * No order CREATE, no Product creation, and no Production inventory writes.
 */
final class DeTianyunWarehouseSyncController
{
    public function start(Request $request, Warehouse $warehouse): JsonResponse
    {
        return $this->queue($request, $warehouse, false);
    }

    public function resume(Request $request, Warehouse $warehouse): JsonResponse
    {
        return $this->queue($request, $warehouse, true);
    }

    private function queue(Request $request, Warehouse $warehouse, bool $resume): JsonResponse
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

        $resumeId = null;
        if ($resume) {
            $data = $request->validate(['resume_from_run_id' => ['required', 'integer', 'min:1']]);
            $previous = DB::table('xlwms_inventory_manual_runs')
                ->where('id', $data['resume_from_run_id'])
                ->where('warehouse_id', $warehouse->id)->first();
            if (! $previous || in_array($previous->status, ['queued', 'running'], true)) {
                return $this->error('XLWMS_DE_RESUME_TARGET_INVALID', 409);
            }
            if (! in_array($previous->outcome, ['failed', 'partial_success', 'preview'], true)
                && ! in_array($previous->status, ['blocked', 'failed', 'not_ready'], true)) {
                return $this->error('XLWMS_DE_RESUME_NOT_NEEDED', 409);
            }
            $resumeId = (int) $previous->id;
        }

        $lock = Cache::lock('xlwms:de:manual-sync:start', 15);
        if (! $lock->get()) {
            return $this->error('XLWMS_DE_SYNC_LOCKED', 409);
        }
        try {
            $this->expireStale($warehouse);
            if (DB::table('xlwms_inventory_manual_runs')
                ->where('warehouse_id', $warehouse->id)->where('outcome', 'needs_reconcile')->exists()) {
                return $this->error('XLWMS_DE_SYNC_RECONCILIATION_REQUIRED', 409);
            }
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
                'run_kind' => $resume ? 'resume' : 'refresh',
                'resume_from_run_id' => $resumeId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            try {
                RunDeTianyunWarehouseSync::dispatch($runId)->onQueue('default');
            } catch (Throwable) {
                DB::table('xlwms_inventory_manual_runs')->where('id', $runId)->update([
                    'status' => 'failed', 'outcome' => 'failed', 'error_code' => 'XLWMS_DE_SYNC_QUEUE_UNAVAILABLE',
                    'finished_at' => now(), 'updated_at' => now(),
                ]);

                return $this->error('XLWMS_DE_SYNC_QUEUE_UNAVAILABLE', 503);
            }

            return response()->json([
                'run_id' => $runId, 'status' => 'queued',
                'run_kind' => $resume ? 'resume' : 'refresh',
                'resume_from_run_id' => $resumeId,
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
            'outcome' => $run->outcome,
            'run_kind' => $run->run_kind,
            'resume_from_run_id' => $run->resume_from_run_id,
            'successful_sku_count' => (int) $run->successful_sku_count,
            'updated_sku_count' => (int) $run->updated_sku_count,
            'unchanged_sku_count' => (int) $run->unchanged_sku_count,
            'failed_sku_count' => (int) $run->failed_sku_count,
            'missing_sku_count' => (int) $run->missing_sku_count,
            'erp_stock_updated' => in_array($run->status, ['applied', 'partial_applied'], true),
            'provider_sku_count' => (int) $run->provider_sku_count,
            'mapped_sku_count' => (int) $run->mapped_sku_count,
            'unmatched_sku_count' => (int) $run->unmatched_sku_count,
            'blocked_sku_count' => (int) $run->blocked_sku_count,
            'error_code' => $run->error_code,
            'finished_at' => $run->finished_at,
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Read persisted, warehouse-scoped evidence only. Never calls the OMS or
     * discloses signed requests, connector credentials, or provider payloads.
     */
    public function details(Request $request, Warehouse $warehouse): JsonResponse
    {
        abort_unless(app()->environment('local', 'testing') && $warehouse->code === 'DE', 404);

        $input = $request->validate([
            'status' => ['nullable', Rule::in(['all', 'unmatched', 'matched', 'blocked', 'catalog_only'])],
            'search' => ['nullable', 'string', 'max:128'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $status = $input['status'] ?? 'unmatched';
        $page = (int) ($input['page'] ?? 1);
        $search = trim((string) ($input['search'] ?? ''));
        $perPage = 50;

        $snapshot = DB::table('xlwms_inventory_snapshots')
            ->where('warehouse_id', $warehouse->id)
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->first(['id', 'status', 'fetched_at', 'applied_at',
                'provider_sku_count', 'mapped_sku_count', 'unmatched_sku_count', 'blocked_sku_count']);

        $runs = Schema::hasTable('xlwms_inventory_manual_runs')
            ? DB::table('xlwms_inventory_manual_runs')
                ->where('warehouse_id', $warehouse->id)->orderByDesc('id')->limit(12)
                ->get(['id', 'status', 'apply_requested', 'snapshot_id', 'provider_sku_count',
                    'mapped_sku_count', 'unmatched_sku_count', 'blocked_sku_count',
                    'error_code', 'created_at', 'finished_at', 'run_kind', 'resume_from_run_id',
                    'outcome', 'successful_sku_count', 'updated_sku_count',
                    'unchanged_sku_count', 'failed_sku_count', 'missing_sku_count'])
                ->map(static fn ($run): array => [
                    'id' => (int) $run->id,
                    'status' => $run->status,
                    'mode' => $run->apply_requested ? 'apply_if_verified' : 'read_only',
                    'snapshot_id' => $run->snapshot_id === null ? null : (int) $run->snapshot_id,
                    'provider_sku_count' => (int) $run->provider_sku_count,
                    'mapped_sku_count' => (int) $run->mapped_sku_count,
                    'unmatched_sku_count' => (int) $run->unmatched_sku_count,
                    'blocked_sku_count' => (int) $run->blocked_sku_count,
                    'error_code' => $run->error_code,
                    'outcome' => $run->outcome,
                    'run_kind' => $run->run_kind,
                    'resume_from_run_id' => $run->resume_from_run_id,
                    'successful_sku_count' => (int) $run->successful_sku_count,
                    'updated_sku_count' => (int) $run->updated_sku_count,
                    'unchanged_sku_count' => (int) $run->unchanged_sku_count,
                    'failed_sku_count' => (int) $run->failed_sku_count,
                    'missing_sku_count' => (int) $run->missing_sku_count,
                    'created_at' => $run->created_at,
                    'finished_at' => $run->finished_at,
                ])->all()
            : [];

        if (! $snapshot) {
            return response()->json([
                'snapshot' => null,
                'counts' => ['matched' => 0, 'unmatched' => 0, 'blocked' => 0, 'catalog_only' => 0],
                'rows' => [], 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => 0],
                'runs' => $runs,
                'latest_run' => $runs[0] ?? null,
            ])->header('Cache-Control', 'no-store, private');
        }

        $byStatus = DB::table('xlwms_inventory_snapshot_rows')
            ->where('snapshot_id', $snapshot->id)
            ->selectRaw('resolution_status, count(*) as row_count')
            ->groupBy('resolution_status')->pluck('row_count', 'resolution_status');
        $counts = [];
        foreach (['matched', 'unmatched', 'blocked', 'catalog_only'] as $key) {
            $counts[$key] = (int) ($byStatus[$key] ?? 0);
        }

        $latestRun = $runs[0] ?? null;
        $detailRunId = $latestRun !== null
            && (int) ($latestRun['snapshot_id'] ?? 0) === (int) $snapshot->id
                ? (int) $latestRun['id'] : 0;
        $query = DB::table('xlwms_inventory_snapshot_rows as r')
            ->leftJoin('products as p', 'p.id', '=', 'r.product_id')
            ->leftJoin('xlwms_inventory_manual_run_rows as rr', static function ($join) use ($detailRunId): void {
                $join->on('rr.provider_sku', '=', 'r.provider_sku')
                    ->where('rr.run_id', '=', $detailRunId);
            })
            ->where('r.snapshot_id', $snapshot->id);
        if ($status !== 'all') {
            $query->where('r.resolution_status', $status);
        }
        if ($search !== '') {
            $query->where('r.provider_sku', 'like', '%'.$search.'%');
        }
        $total = (clone $query)->count();
        $rows = $query->orderBy('r.provider_sku')->forPage($page, $perPage)
            ->get(['r.provider_sku', 'p.sku as erp_sku', 'r.resolution_status',
                'r.oms_total', 'r.oms_available', 'r.oms_locked',
                'r.oms_in_transit', 'r.mismatch_reason', 'rr.outcome as sync_outcome',
                'rr.erp_before', 'rr.erp_after', 'rr.delta', 'rr.write_verified',
                'rr.error_code as sync_error_code']);

        return response()->json([
            'snapshot' => [
                'id' => (int) $snapshot->id,
                'status' => $snapshot->status,
                'fetched_at' => $snapshot->fetched_at,
                'applied_at' => $snapshot->applied_at,
                'erp_stock_updated' => in_array($snapshot->status, ['applied', 'partial_applied'], true),
                'provider_sku_count' => (int) $snapshot->provider_sku_count,
                'mapped_sku_count' => (int) $snapshot->mapped_sku_count,
                'unmatched_sku_count' => (int) $snapshot->unmatched_sku_count,
                'blocked_sku_count' => (int) $snapshot->blocked_sku_count,
            ],
            'counts' => $counts,
            'rows' => $rows,
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
            'runs' => $runs,
            'latest_run' => $latestRun,
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
                'status' => 'failed', 'outcome' => 'failed', 'error_code' => 'XLWMS_DE_SYNC_TIMEOUT',
                'finished_at' => now(), 'updated_at' => now(),
            ]);
    }

    private function error(string $code, int $httpStatus): JsonResponse
    {
        return response()->json(['error_code' => $code], $httpStatus)
            ->header('Cache-Control', 'no-store, private');
    }
}
