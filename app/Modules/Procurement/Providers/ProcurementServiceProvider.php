<?php

namespace App\Modules\Procurement\Providers;

use Illuminate\Support\ServiceProvider;

final class ProcurementServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
    }
}
