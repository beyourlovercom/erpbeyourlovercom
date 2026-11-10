<?php

use App\Modules\Logistics\Application\ShipOut\ShipOutProductionDispatchState;
use App\Modules\Logistics\Application\ShipOut\ShipOutProductionOmsReadbackService;
use App\Modules\Logistics\Application\ShipOut\ShipOutSandboxLabelStage;
use App\Modules\Sync\Application\Contracts\SyncOrchestrationRepository;
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

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('runtime:health --quiet --no-interaction')
    ->everyFiveMinutes()
    ->name('runtime.health')
    ->withoutOverlapping(10)
    ->onOneServer();

Schedule::command('backup:scheduled-run daily')
    ->dailyAt((string) config('backup.automatic.time', '02:00'))
    ->name('backup.scheduled.daily')
    ->timezone((string) config('backup.automatic.timezone', 'UTC'))
    ->withoutOverlapping(180)
    ->onOneServer();

Schedule::command('backup:scheduled-run weekly')
    ->weeklyOn((int) config('backup.automatic.weekly_day', 1), (string) config('backup.automatic.time', '02:00'))
    ->name('backup.scheduled.weekly')
    ->timezone((string) config('backup.automatic.timezone', 'UTC'))
    ->withoutOverlapping(180)
    ->onOneServer();

Schedule::command('backup:scheduled-run monthly')
    ->monthlyOn((int) config('backup.automatic.monthly_day', 1), (string) config('backup.automatic.time', '02:00'))
    ->name('backup.scheduled.monthly')
    ->timezone((string) config('backup.automatic.timezone', 'UTC'))
    ->withoutOverlapping(180)
    ->onOneServer();

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
