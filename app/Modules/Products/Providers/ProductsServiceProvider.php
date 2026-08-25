<?php

namespace App\Modules\Products\Providers;

use App\Modules\Products\Application\Contracts\ProductRepository;
use App\Modules\Products\Experience\Console\ImportWooCsvCommand;
use App\Modules\Products\Infrastructure\EloquentProductRepository;
use Illuminate\Support\ServiceProvider;

final class ProductsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProductRepository::class, EloquentProductRepository::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([ImportWooCsvCommand::class]);
        }
    }
}
