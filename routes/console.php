<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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
        return \Illuminate\Console\Command::SUCCESS;
    }

    $limit = min(50, max(1, (int) config('tracking_sync_v2.max_batch', 50)));
    $minimum = (int) $baseline;
    $minimumShipment = (int) $shipmentBaseline;
    $repo = app(\App\Modules\Sync\Application\Contracts\SyncOrchestrationRepository::class);
    $due = $repo->dueTrackingOutbox($limit, $minimum)->count();
    $available = max(0, $limit - $due);
    $newShipments = $available > 0 ? $repo->eligibleTrackingOrders($available, $minimumShipment)->count() : 0;

    if (! $this->option('apply')) {
        $this->info(json_encode([
            'mode' => 'preview', 'due_retry' => $due, 'new_shipments' => $newShipments,
            'min_outbox_id_exclusive' => $minimum, 'min_shipment_id_exclusive' => $minimumShipment,
        ]));
        return \Illuminate\Console\Command::SUCCESS;
    }

    if ($due === 0 && $newShipments === 0) {
        $this->info(json_encode(['mode' => 'apply', 'status' => 'no_work']));
        return \Illuminate\Console\Command::SUCCESS;
    }

    $sync = app(\App\Modules\Sync\Application\SyncOrchestrationService::class);
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
            'status' => $run->status instanceof \BackedEnum ? $run->status->value : (string) $run->status,
            'requested' => (int) $run->requested_items,
            'succeeded' => (int) $run->succeeded_items,
            'failed' => (int) $run->failed_items,
        ]));
    }

    return $failures ? \Illuminate\Console\Command::FAILURE : \Illuminate\Console\Command::SUCCESS;
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
    && in_array('6', explode(',', str_replace(' ', '', (string)config('shipout.sandbox_enabled_modes','9'))), true)) {
    Artisan::command('shipout:prune-label-stages', function (): int {
        if (! \Illuminate\Support\Facades\Schema::hasTable('shipout_label_stages')) {
            return \Illuminate\Console\Command::SUCCESS;
        }
        $count=app(\App\Modules\Logistics\Application\ShipOut\ShipOutSandboxLabelStage::class)->prune();
        $this->info('Expired/consumed ShipOut label stages deleted: '.$count);
        return \Illuminate\Console\Command::SUCCESS;
    })->purpose('Delete expired or consumed sandbox ShipOut encrypted PDF label stages');
    Schedule::command('shipout:prune-label-stages --no-interaction')
        ->dailyAt('03:10')->name('shipout.label-stages.prune')
        ->withoutOverlapping(25);
}
