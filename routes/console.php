<?php

use App\Modules\Inventory\Application\MorePlusInventorySyncService;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionDispatchState;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionOmsReadbackService;
use App\Modules\Logistics\Application\ShipOut\ShipOutSandboxLabelStage;
use App\Modules\Logistics\Application\Xlwms\DeAuthoritativePhysicalStockSyncService;
use App\Modules\Logistics\Application\Xlwms\DeTianyunStatusRefresh;
use App\Modules\Logistics\Experience\Jobs\ProcessDeTianyunOutboundBatch;
use App\Modules\Sync\Application\Contracts\SyncOrchestrationRepository;
use App\Modules\Sync\Application\OrderSyncProgressSnapshot;
use App\Modules\Sync\Application\SyncOrchestrationService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

if ((bool) config('xlwms.inventory_sync_schedule_enabled', false)) {
    $xlwmsSyncMode = config('xlwms.inventory_apply_enabled', false) && config('xlwms.lot_reconciliation_v2_enabled', false)
        ? '--apply' : '--dry-run';
    Schedule::command('xlwms:sync-inventory '.$xlwmsSyncMode)
        ->cron('*/'.(int) config('xlwms.inventory_sync_schedule_minutes', 30).' * * * *')
        ->name('xlwms.inventory.sync')->withoutOverlapping();
}

// Signed GET only; reuse the existing ERP mirror and its per-SKU quarantine.
Artisan::command('shipout:sync-inventory', function (MorePlusInventorySyncService $sync): int {
    if (! app()->environment('production') || ! config('shipout.production_inventory_schedule_enabled', false)
        || ! config('shipout.production_read_enabled', false)) {
        $this->error('MOREPLUS_SCHEDULE_DISABLED');

        return Command::FAILURE;
    }
    try {
        $warehouse = Warehouse::query()->whereRaw('UPPER(code) = ?', ['US-LA'])->where('is_active', true)->sole();
        $result = $sync->syncProvider($warehouse, null);
        $this->line(json_encode(array_intersect_key($result, array_flip(['updated', 'created', 'unchanged', 'blocked', 'absent_zeroed'])), JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    } catch (Throwable $error) {
        report($error);
        $this->error('MOREPLUS_INVENTORY_SYNC_FAILED');

        return Command::FAILURE;
    }
});

if (app()->environment('production') && config('shipout.production_inventory_schedule_enabled', false)
    && config('shipout.production_read_enabled', false)) {
    Schedule::command('shipout:sync-inventory --no-interaction')->everyTenMinutes()
        ->name('shipout.moreplus.inventory.sync')->withoutOverlapping(15)->runInBackground();
}

// DE/Hannover owner policy: the OMS is physical-stock authority. The CLI
// applies verified per-SKU stock only when separately enabled; all historical
// order reservations and FIFO/aging ledgers remain unchanged.
Artisan::command('xlwms:sync-de-physical {--apply} {--dry-run}', function (DeAuthoritativePhysicalStockSyncService $sync): int {
    if ($this->option('apply') && $this->option('dry-run')) {
        $this->error('Choose --apply or --dry-run.');

        return Command::INVALID;
    }
    try {
        $result = $sync->run((bool) $this->option('apply'));
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    } catch (Throwable $error) {
        $this->error('DE_PHYSICAL_SYNC_FAILED:'.$error->getMessage());

        return Command::FAILURE;
    }
});

// DE/Hannover schedule is isolated from US-SM, defaults OFF and uses one
// cross-worker lock. A read-only scheduled fetch never silently applies stock.
if ((bool) config('xlwms.de_inventory_sync_schedule_enabled', false)) {
    $deApplyReady = (bool) config('xlwms.de_authoritative_auto_apply_enabled', false);
    Schedule::command('xlwms:sync-de-physical '.($deApplyReady ? '--apply' : '--dry-run'))
        ->cron('*/'.(int) config('xlwms.de_inventory_sync_schedule_minutes', 30).' * * * *')
        ->name('xlwms.de.inventory.sync')->withoutOverlapping();
}

// DE production batches: one durable claimed item per scheduler invocation.
// Dedicated long-running process, not the shared 55-second/90-second queue.
Artisan::command('xlwms:drain-de-batch', function (): int {
    if (! app()->environment('production') || ! config('xlwms.de_operator_batch_enabled', false)
        || ! config('xlwms.de_order_batch_live_enabled', false)) {
        return Command::SUCCESS;
    }
    $id = DB::table('xlwms_de_dispatch_batches')->where('dry_run', false)
        ->whereIn('status', ['queued', 'running'])->orderBy('created_at')->value('id');
    if ($id) {
        ProcessDeTianyunOutboundBatch::dispatchSync($id);
    }

    return Command::SUCCESS;
});
Artisan::command('xlwms:refresh-de-status', function (): int {
    if (app()->environment('production') && config('xlwms.de_status_refresh_enabled', false)) {
        $this->info(json_encode(app(DeTianyunStatusRefresh::class)->run()));
    }

    return Command::SUCCESS;
});
if (app()->environment('production') && config('xlwms.de_operator_batch_enabled', false) && config('xlwms.de_order_batch_live_enabled', false)) {
    Schedule::command('xlwms:drain-de-batch')->everyMinute()
        ->name('xlwms.de.outbound.drain')->withoutOverlapping(15)->onOneServer()->runInBackground();
}
if (app()->environment('production') && config('xlwms.de_status_refresh_enabled', false)) {
    Schedule::command('xlwms:refresh-de-status')->everyFiveMinutes()
        ->name('xlwms.de.outbound.refresh')->withoutOverlapping(5)->onOneServer()->runInBackground();
}

// Read-only Sync status index: the full Woo batch inspection must never
// block a human /sync/orders filter request. No Woo writes are performed.
Artisan::command('sync:refresh-progress-index', function (): int {
    try {
        $snapshot = app(OrderSyncProgressSnapshot::class)->refresh();
        $this->line(json_encode([
            'result' => 'ok',
            'generated_at' => $snapshot['generated_at'],
            'total_orders' => $snapshot['total'],
            'candidate_orders' => $snapshot['candidates'],
            'remote_configured' => $snapshot['remote_configured'],
        ]));

        return Command::SUCCESS;
    } catch (Throwable $exception) {
        report($exception);
        $this->error('只读全库状态核验失败：'.get_class($exception));

        return Command::FAILURE;
    }
})->purpose('Build a read-only cached status index for Sync AG Grid filtering');

// A full verified Woo status index can take over a minute. Run it as a
// background schedule event so it never delays the independent 30-minute
// orders/Tracking sync jobs. Existing locks prevent overlapping refreshes.
Schedule::command('sync:refresh-progress-index --no-interaction')
    ->everyFiveMinutes()
    ->name('sync.orders.progress-index')
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('runtime:health --quiet --no-interaction')
    ->everyFiveMinutes()
    ->name('runtime.health')
    ->withoutOverlapping(10)
    ->onOneServer();

// Database backup V1 is manual only; legacy scheduled backup commands are not scheduled.

if ((bool) config('services.woocommerce.product_sync_enabled', false)) {
    Schedule::command('products:sync-woocommerce --incremental')
        ->hourly()
        ->name('products.woocommerce.inbound.hourly')
        ->withoutOverlapping(55)
        ->onOneServer();
}

if ((bool) config('services.woocommerce.order_sync_enabled', false)) {
    Schedule::command('orders:sync-woocommerce --no-interaction')
        ->everyThirtyMinutes()
        ->name('orders.woocommerce.inbound.half-hourly')
        ->withoutOverlapping(55)
        ->onOneServer();
}

// Tracking is an independent outbound channel. A cutover snapshot is mandatory;
// enabling this never drains historical failed/pending Tracking intents.
Artisan::command('tracking:retry-woocommerce {--apply}', function (): int {
    $baseline = (string) config('tracking_sync_v2.min_outbox_id', '');
    $shipmentBaseline = (string) config('tracking_sync_v2.min_shipment_id', '');
    if (! (bool) config('tracking_sync_v2.periodic_enabled', false)
        || ! ctype_digit($baseline) || (int) $baseline < 1
        || ! ctype_digit($shipmentBaseline)) {
        $this->warn('Tracking V2 is disabled or reviewed Outbox/Shipment activation baselines are missing.');

        return Command::SUCCESS;
    }

    $limit = min(50, max(1, (int) config('tracking_sync_v2.max_batch', 50)));
    $minimum = (int) $baseline;
    $minimumShipment = (int) $shipmentBaseline;
    $repo = app(SyncOrchestrationRepository::class);
    $due = $repo->dueTrackingOutbox($limit, $minimum)->count();
    $available = max(0, $limit - $due);
    $newShipments = $available > 0 ? $repo->eligibleTrackingOrders($available, $minimumShipment)->count() : 0;

    if (! $this->option('apply')) {
        $this->info(json_encode([
            'mode' => 'preview', 'due_retry' => $due, 'new_shipments' => $newShipments,
            'min_outbox_id_exclusive' => $minimum, 'min_shipment_id_exclusive' => $minimumShipment,
        ]));

        return Command::SUCCESS;
    }

    if ($due === 0 && $newShipments === 0) {
        $this->info(json_encode(['mode' => 'apply', 'status' => 'no_work']));

        return Command::SUCCESS;
    }

    $sync = app(SyncOrchestrationService::class);
    $runs = [];
    if ($due > 0) {
        $runs[] = $sync->retryPending(null, $due, $minimum);
    }
    if ($newShipments > 0) {
        $runs[] = $sync->trackingAllPending(null, $newShipments, $minimumShipment);
    }

    $failures = 0;
    foreach ($runs as $run) {
        $failures += (int) $run->failed_items;
        $this->info(json_encode([
            'mode' => 'apply',
            'run_id' => (int) $run->id,
            'status' => $run->status instanceof BackedEnum ? $run->status->value : (string) $run->status,
            'requested' => (int) $run->requested_items,
            'succeeded' => (int) $run->succeeded_items,
            'failed' => (int) $run->failed_items,
        ]));
    }

    return $failures ? Command::FAILURE : Command::SUCCESS;
})->purpose('Retry only post-activation Woo tracking outbox rows; preview by default.');

$trackingOutboxBaseline = (string) config('tracking_sync_v2.min_outbox_id', '');
$trackingShipmentBaseline = (string) config('tracking_sync_v2.min_shipment_id', '');
if ((bool) config('tracking_sync_v2.periodic_enabled', false)
    && ctype_digit($trackingOutboxBaseline) && (int) $trackingOutboxBaseline >= 1
    && ctype_digit($trackingShipmentBaseline)) {
    Schedule::command('tracking:retry-woocommerce --apply --no-interaction')
        ->everyThirtyMinutes()
        ->name('tracking.woocommerce.outbound.retry.half-hourly')
        ->withoutOverlapping(25)
        ->onOneServer();
}

if ((bool) config('erp_accounting.continuous_delta.enabled', false)) {
    Schedule::command('finance:materialize-continuous-delta --apply --limit=10000 --no-interaction')
        ->hourlyAt(10)
        ->name('finance.accounting.continuous-delta.hourly')
        ->withoutOverlapping(50)
        ->onOneServer();
}

if ((bool) config('inventory_auto_allocation.scheduler_enabled', false)) {
    Schedule::command('inventory:auto-allocate --limit='.(int) config('inventory_auto_allocation.catch_up_limit', 100).' --apply')
        ->hourly()
        ->name('inventory.auto-allocation.catch-up.hourly')
        ->withoutOverlapping(55)
        ->onOneServer();
}
if ((bool) config('warehouse_storage_aging.monthly_enabled', false)) {
    Schedule::command('inventory:run-storage-aging-monthly')
        ->monthlyOn(1, (string) config('warehouse_storage_aging.scheduler.time', '02:20'))
        ->timezone((string) config('warehouse_storage_aging.scheduler.timezone', config('app.timezone', 'UTC')))
        ->name('inventory.storage-aging.monthly')
        ->withoutOverlapping(180)
        ->onOneServer();
}

// Expired encrypted ShipOut sandbox labels are disposable and must not be
// retained indefinitely. Enabled only for an authorized mode 6 environment.
if (! app()->environment('production')
    && in_array('6', explode(',', str_replace(' ', '', (string) config('shipout.sandbox_enabled_modes', '9'))), true)) {
    Artisan::command('shipout:prune-label-stages', function (): int {
        if (! Schema::hasTable('shipout_label_stages')) {
            return Command::SUCCESS;
        }
        $count = app(ShipOutSandboxLabelStage::class)->prune();
        $this->info('Expired/consumed ShipOut label stages deleted: '.$count);

        return Command::SUCCESS;
    })->purpose('Delete expired or consumed sandbox ShipOut encrypted PDF label stages');
    Schedule::command('shipout:prune-label-stages --no-interaction')
        ->dailyAt('03:10')->name('shipout.label-stages.prune')
        ->withoutOverlapping(25);
}

// R12: separately gated signed OMS GET-only observation loop. This command
// never submits/retries an order, mutates fulfillment, or touches Woo.
Artisan::command('shipout:reconcile-oms {--apply} {--limit=20}', function (): int {
    $limit = min(50, max(1, (int) $this->option('limit')));
    if (! app()->environment('production')
        || ! (bool) config('shipout.production_read_enabled', false)
        || ! (bool) config('shipout.oms_reconciliation_enabled', false)) {
        $this->warn('R12 OMS readback not authorized in this environment.');

        return Command::SUCCESS;
    }

    $ids = DB::table('shipout_dispatch_attempts as a')
        ->join('shipments as s', 's.order_id', '=', 'a.order_id')
        ->where('a.provider_warehouse_id', ShipOutProductionDispatchState::MOREPLUS_PROVIDER_WAREHOUSE_ID)
        ->where('s.shipment_status', 'pending')
        // shipout_dispatch_attempts.order_id is UNIQUE: DISTINCT is not
        // needed and MySQL 8 rejects DISTINCT ordered by unselected a.id.
        ->orderBy('a.id')->limit($limit)
        ->pluck('a.order_id')->map(fn ($id) => (int) $id)->all();

    if (! $this->option('apply')) {
        $this->info(json_encode(['mode' => 'preview', 'readback_candidates' => count($ids)]));

        return Command::SUCCESS;
    }

    $result = ['mode' => 'signed_get_and_local_audit', 'checked' => 0, 'unverified' => 0];
    $observer = app(ShipOutProductionOmsReadbackService::class);
    foreach ($ids as $id) {
        try {
            $observer->inspectAndRecord($id);
            $result['checked']++;
        } catch (Throwable) {
            // Never log provider payload, recipient PII, credentials or exceptions.
            // The original one-shot ledger retains its permanent no-POST lock.
            $result['unverified']++;
        }
    }
    $this->info(json_encode($result));

    return Command::SUCCESS;
})->purpose('R12 signed GET-only MorePlus OMS snapshots; zero API POST.');

if (app()->environment('production') && (bool) config('shipout.oms_reconciliation_enabled', false)) {
    Schedule::command('shipout:reconcile-oms --apply --no-interaction')
        ->everyThirtyMinutes()
        ->name('shipout.moreplus.oms-readback.half-hourly')
        ->withoutOverlapping(25)
        ->onOneServer();
}
