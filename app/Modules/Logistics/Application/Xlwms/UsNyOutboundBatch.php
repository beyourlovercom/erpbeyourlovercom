<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Logistics\Application\LogisticsExecutionAccess;
use App\Modules\Logistics\Application\OverseaWarehouseQueueQuery;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

final class UsNyOutboundBatch
{
    public const MAX = 200;

    // Process-local authorization plus a durable exact claim. Never set canary flags dynamically.
    private static ?array $execution = null;

    public function __construct(private readonly UsNyOutboundBatchPreview $preview, private readonly UsNyCanaryDispatch $dispatch) {}

    public static function visible(): bool
    {
        return app()->environment('local', 'testing') || (app()->environment('production') && config('xlwms.us_ny_batch_read_enabled'));
    }

    public static function liveEnabled(): bool
    {
        return app()->environment('production', 'testing') && config('xlwms.us_ny_batch_read_enabled')
            && config('xlwms.us_ny_operator_batch_enabled') && config('xlwms.us_ny_batch_owner_approved')
            && config('xlwms.us_ny_inventory_physical_apply_enabled');
    }

    public static function authorizedClaim(int $id, int $actor): bool
    {
        if (! self::liveEnabled() || self::$execution === null || self::$execution['order'] !== $id || self::$execution['actor'] !== $actor) {
            return false;
        }

        return DB::table('xlwms_ny_dispatch_batch_items as i')->join('xlwms_ny_dispatch_batches as b', 'b.id', '=', 'i.batch_id')
            ->where('i.id', self::$execution['item'])->where('i.order_id', $id)->where('i.payload_hash', self::$execution['hash'])
            ->where('i.status', 'processing')->where('b.status', 'running')->where('b.operator_user_id', $actor)->where('b.dry_run', false)->exists();
    }

    public static function guarded(int $id): bool
    {
        return Schema::hasTable('xlwms_ny_dispatch_batch_items') && DB::table('xlwms_ny_dispatch_batch_items as i')
            ->join('xlwms_ny_dispatch_batches as b', 'b.id', '=', 'i.batch_id')->where('i.order_id', $id)
            ->where('b.dry_run', false)->whereIn('i.status', ['queued', 'processing', 'uncertain'])->exists();
    }

    public function preview(?array $selected): array
    {
        $ids = $selected;
        if ($ids === null) {
            $q = Shipment::query()->whereHas('warehouse', fn ($w) => $w->where('code', 'US-NY'))->whereHas('order', fn ($o) => $o->eligibleForOperations());
            app(OverseaWarehouseQueueQuery::class)->applyView($q, 'pending');
            $ids = $q->orderBy('order_id')->limit(self::MAX + 1)->pluck('order_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        }
        $overflow = count($ids) > self::MAX;
        $ids = array_slice($ids, 0, self::MAX);
        $data = $ids === [] ? ['items' => [], 'count' => 0] : $this->preview->preview($ids);
        $items = $data['items'];
        foreach ($items as &$row) {
            if (self::guarded((int) $row['order_id'])) {
                $row['ready'] = false;
                $row['blockers'][] = 'US_NY_BATCH_ALREADY_CLAIMED';
            }
        }
        unset($row);
        $count = count(array_filter($items, fn ($r) => $r['ready']));

        return ['scope' => $selected === null ? 'all' : 'selected', 'scan_count' => count($ids), 'count' => $count, 'excluded_count' => count($ids) - $count, 'overflow' => $overflow,
            'dry_run' => ! app()->environment('production'), 'items' => $items, 'fingerprint' => hash('sha256', json_encode([$selected, $items, $overflow], JSON_THROW_ON_ERROR))];
    }

    public function start(int $actor, ?array $ids, string $fingerprint, string $confirmation): array
    {
        $u = User::find($actor);
        if (! $u?->is_active || ! app(LogisticsExecutionAccess::class)->allows($u)
            || (! app()->environment('local', 'testing') && ! self::liveEnabled())) {
            throw new RuntimeException('US_NY_BATCH_NOT_AUTHORIZED');
        }
        $lock = Cache::lock('xlwms:ny:batch:start', 60);
        if (! $lock->get()) {
            throw new RuntimeException('US_NY_BATCH_LOCKED');
        }
        try {
            return DB::transaction(function () use ($actor, $ids, $fingerprint, $confirmation) {
                $p = $this->preview($ids);
                if ($p['overflow'] || ! $p['count'] || ! hash_equals($p['fingerprint'], $fingerprint) || $confirmation !== 'CONFIRM_US_NY_BATCH_'.$p['count']) {
                    throw new RuntimeException('US_NY_BATCH_STALE');
                }
                if (DB::table('xlwms_ny_dispatch_batches')->whereIn('status', ['queued', 'running'])->exists()) {
                    throw new RuntimeException('US_NY_BATCH_ALREADY_RUNNING');
                }
                $id = (string) Str::uuid();
                $dry = $p['dry_run'];
                DB::table('xlwms_ny_dispatch_batches')->insert(['id' => $id, 'operator_user_id' => $actor, 'status' => 'queued', 'dry_run' => $dry, 'total' => $p['count'], 'created_at' => now(), 'updated_at' => now()]);
                foreach ($p['items'] as $item) {
                    if ($item['ready']) {
                        DB::table('xlwms_ny_dispatch_batch_items')->insert(['batch_id' => $id, 'order_id' => $item['order_id'], 'order_no' => $item['order_no'], 'payload_hash' => $item['payload_hash'], 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
                    }
                }

                return ['batch_id' => $id, 'status' => 'queued', 'dry_run' => $dry, 'total' => $p['count']];
            });
        } finally {
            $lock->release();
        }
    }

    public function status(string $id): array
    {
        $batch = DB::table('xlwms_ny_dispatch_batches')->where('id', $id)->first();
        if (! $batch) {
            throw new RuntimeException('US_NY_BATCH_NOT_FOUND');
        }
        $items = DB::table('xlwms_ny_dispatch_batch_items')->where('batch_id', $id)->orderBy('id')->get(['order_id', 'order_no', 'status', 'result_code']);

        return ['batch_id' => $id, 'status' => $batch->status, 'dry_run' => (bool) $batch->dry_run, 'total' => (int) $batch->total, 'processed' => $items->whereNotIn('status', ['queued', 'processing'])->count(), 'items' => $items];
    }

    public function drain(int $limit = 1, ?string $simulationId = null): array
    {
        $lock = Cache::lock('xlwms:ny:batch:drain', 900);
        if (! $lock->get()) {
            return ['busy' => true];
        }
        try {
            $q = DB::table('xlwms_ny_dispatch_batches')->whereIn('status', ['queued', 'running']);
            if ($simulationId !== null) {
                if (! app()->environment('local', 'testing')) {
                    return ['processed' => 0];
                }
                $q->where('id', $simulationId)->where('dry_run', true);
            } else {
                if (! self::liveEnabled()) {
                    return ['processed' => 0];
                }
                $q->where('dry_run', false);
            }
            $b = $q->orderBy('created_at')->first();
            if (! $b) {
                return ['processed' => 0];
            }
            $actor = User::find($b->operator_user_id);
            if (! $actor?->is_active || ! app(LogisticsExecutionAccess::class)->allows($actor)) {
                DB::table('xlwms_ny_dispatch_batch_items')->where('batch_id', $b->id)->where('status', 'queued')->update(['status' => 'skipped', 'result_code' => 'US_NY_OPERATOR_REVOKED', 'updated_at' => now()]);
            }
            DB::table('xlwms_ny_dispatch_batches')->where('id', $b->id)->update(['status' => 'running', 'updated_at' => now()]);
            DB::table('xlwms_ny_dispatch_batch_items')->where('batch_id', $b->id)->where('status', 'processing')
                ->update(['status' => 'uncertain', 'result_code' => 'US_NY_INTERRUPTED_RECONCILE', 'updated_at' => now()]);
            $items = DB::table('xlwms_ny_dispatch_batch_items')->where('batch_id', $b->id)->where('status', 'queued')->orderBy('id')->limit(min(self::MAX, max(1, $limit)))->get();
            foreach ($items as $item) {
                $status = 'skipped';
                $code = 'US_NY_PREFLIGHT_CHANGED';
                if (DB::table('xlwms_ny_dispatch_batch_items')->where('id', $item->id)->where('status', 'queued')->update(['status' => 'processing', 'updated_at' => now()]) !== 1) {
                    continue;
                }
                try {
                    $p = $this->preview->preview([(int) $item->order_id])['items'][0];
                    if (! $p['ready'] || ! hash_equals($item->payload_hash, (string) $p['payload_hash'])) {
                        throw new RuntimeException('US_NY_PREFLIGHT_CHANGED');
                    }
                    if ($b->dry_run) {
                        $status = 'simulated';
                        $code = 'US_NY_DEV_NO_IO';
                    } else {
                        self::$execution = ['order' => (int) $item->order_id, 'actor' => (int) $b->operator_user_id, 'item' => (int) $item->id, 'hash' => $item->payload_hash];
                        $r = $this->dispatch->create((int) $item->order_id, (int) $b->operator_user_id, $item->payload_hash);
                        $status = match ($r['state']) {
                            'oms_create_confirmed' => 'oms_visible','uncertain_manual_reconcile' => 'uncertain',default => 'skipped'
                        };
                        $code = $r['state'];
                    }
                } catch (\Throwable) {
                    $attempt = XlwmsDispatchAttempt::where('order_id', $item->order_id)->first();
                    if ($attempt && in_array($attempt->state, ['request_in_flight', 'oms_create_acknowledged', 'uncertain_manual_reconcile', 'oms_create_confirmed'], true)) {
                        $status = 'uncertain';
                        $code = 'US_NY_RESULT_RECONCILE';
                    }
                } finally {
                    self::$execution = null;
                }
                DB::table('xlwms_ny_dispatch_batch_items')->where('id', $item->id)->update(['status' => $status, 'result_code' => $code, 'updated_at' => now()]);
            }
            if (! DB::table('xlwms_ny_dispatch_batch_items')->where('batch_id', $b->id)->whereIn('status', ['queued', 'processing'])->exists()) {
                $bad = DB::table('xlwms_ny_dispatch_batch_items')->where('batch_id', $b->id)->whereNotIn('status', ['oms_visible', 'simulated'])->exists();
                DB::table('xlwms_ny_dispatch_batches')->where('id', $b->id)->update(['status' => $bad ? 'completed_partial' : 'completed', 'finished_at' => now(), 'updated_at' => now()]);
            }

            return ['processed' => $items->count()];
        } finally {
            $lock->release();
        }
    }
}
