<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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
        ->hourly()
        ->name('orders.woocommerce.inbound.hourly')
        ->withoutOverlapping(55)
        ->onOneServer();
}
