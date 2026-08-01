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
