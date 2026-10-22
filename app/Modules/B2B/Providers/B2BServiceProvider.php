<?php

namespace App\Modules\B2B\Providers;

use Illuminate\Support\ServiceProvider;

final class B2BServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
    }
}
