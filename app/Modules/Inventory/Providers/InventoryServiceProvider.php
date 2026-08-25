<?php

namespace App\Modules\Inventory\Providers;

use App\Modules\Inventory\Application\Contracts\InventoryRepository;
use App\Modules\Inventory\Infrastructure\EloquentInventoryRepository;
use Illuminate\Support\ServiceProvider;

final class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void { $this->app->bind(InventoryRepository::class,EloquentInventoryRepository::class); }
    public function boot(): void { $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php'); }
}
