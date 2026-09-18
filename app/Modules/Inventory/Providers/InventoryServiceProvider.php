<?php

namespace App\Modules\Inventory\Providers;

use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use App\Modules\Inventory\Application\Contracts\InventoryBatchSyncRepository;
use App\Modules\Inventory\Application\Contracts\InventoryRepository;
use App\Modules\Inventory\Application\Contracts\InventoryReservationRepository;
use App\Modules\Inventory\Application\Contracts\WarehouseCostAllocationEvidenceReader;
use App\Modules\Inventory\Infrastructure\EloquentInventoryAvailabilityReader;
use App\Modules\Inventory\Infrastructure\EloquentInventoryBatchSyncRepository;
use App\Modules\Inventory\Infrastructure\EloquentInventoryRepository;
use App\Modules\Inventory\Infrastructure\EloquentInventoryReservationRepository;
use App\Modules\Inventory\Infrastructure\EloquentWarehouseCostAllocationEvidenceReader;
use Illuminate\Support\ServiceProvider;
use App\Modules\Inventory\Experience\Console\PackagingScanPreviewCommand;
use App\Modules\Inventory\Experience\Console\ImportWarehouseStorageCostCommand;
use App\Modules\Inventory\Experience\Console\BridgeMorePlusCostsCommand;
use App\Modules\Inventory\Experience\Console\AccrueWarehouseStorageAgingCostCommand;

final class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(InventoryRepository::class, EloquentInventoryRepository::class);
        $this->app->bind(InventoryAvailabilityReader::class, EloquentInventoryAvailabilityReader::class);
        $this->app->bind(InventoryBatchSyncRepository::class, EloquentInventoryBatchSyncRepository::class);
        $this->app->bind(InventoryReservationRepository::class, EloquentInventoryReservationRepository::class);
        $this->app->bind(WarehouseCostAllocationEvidenceReader::class, EloquentWarehouseCostAllocationEvidenceReader::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
        if ($this->app->runningInConsole()) $this->commands([PackagingScanPreviewCommand::class, ImportWarehouseStorageCostCommand::class, BridgeMorePlusCostsCommand::class, AccrueWarehouseStorageAgingCostCommand::class]);
    }
}
