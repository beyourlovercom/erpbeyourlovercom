<?php

namespace App\Modules\Logistics\Providers;

use Illuminate\Support\ServiceProvider;

final class LogisticsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
    }
}
