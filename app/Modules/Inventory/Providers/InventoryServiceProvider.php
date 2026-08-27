<?php

namespace App\Modules\Inventory\Providers;

use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use App\Modules\Inventory\Application\Contracts\InventoryBatchSyncRepository;
use App\Modules\Inventory\Application\Contracts\InventoryRepository;
use App\Modules\Inventory\Infrastructure\EloquentInventoryAvailabilityReader;
use App\Modules\Inventory\Infrastructure\EloquentInventoryBatchSyncRepository;
use App\Modules\Inventory\Infrastructure\EloquentInventoryRepository;
use Illuminate\Support\ServiceProvider;

final class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(InventoryRepository::class, EloquentInventoryRepository::class);
        $this->app->bind(InventoryAvailabilityReader::class, EloquentInventoryAvailabilityReader::class);
        $this->app->bind(InventoryBatchSyncRepository::class, EloquentInventoryBatchSyncRepository::class);
    }
    public function boot(): void { $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php'); }
}
