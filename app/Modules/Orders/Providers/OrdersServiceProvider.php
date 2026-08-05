<?php

namespace App\Modules\Orders\Providers;

use Illuminate\Support\ServiceProvider;

final class OrdersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
    }
}
