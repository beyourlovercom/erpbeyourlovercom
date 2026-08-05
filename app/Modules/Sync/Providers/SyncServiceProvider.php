<?php

namespace App\Modules\Sync\Providers;

use App\Modules\Sync\Application\SyncOutboxService;
use App\Modules\Sync\Application\Contracts\SyncOutboxRepository;
use App\Modules\Sync\Infrastructure\EloquentSyncOutboxRepository;
use Illuminate\Support\ServiceProvider;

final class SyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        class_exists('App\\Support\\Sync\\SyncOutboxWriteException');
        $this->app->bind(SyncOutboxRepository::class, EloquentSyncOutboxRepository::class);
        $this->app->alias('App\\Support\\Sync\\SyncOutboxService', SyncOutboxService::class);
    }

    public function boot(): void
    {
    }
}
