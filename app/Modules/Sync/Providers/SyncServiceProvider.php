<?php

namespace App\Modules\Sync\Providers;

use App\Modules\Sync\Application\SyncOutboxService;
use Illuminate\Support\ServiceProvider;

final class SyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        class_exists('App\\Support\\Sync\\SyncOutboxWriteException');
        $this->app->alias('App\\Support\\Sync\\SyncOutboxService', SyncOutboxService::class);
    }

    public function boot(): void
    {
    }
}
