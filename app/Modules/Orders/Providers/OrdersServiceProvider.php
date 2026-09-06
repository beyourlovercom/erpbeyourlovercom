<?php

namespace App\Modules\Orders\Providers;

use App\Modules\Orders\Application\Contracts\WooCommerceOrderGateway;
use App\Modules\Orders\Infrastructure\WooCommerce\HttpWooCommerceOrderGateway;
use Illuminate\Support\ServiceProvider;

final class OrdersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WooCommerceOrderGateway::class, HttpWooCommerceOrderGateway::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([\App\Modules\Orders\Experience\Console\SyncWooCommerceOrdersCommand::class]);
        }
    }
}
