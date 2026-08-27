<?php

namespace App\Modules\Kol\Providers;

use Illuminate\Support\ServiceProvider;

final class KolServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
    }
}
