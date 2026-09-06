<?php

namespace App\Modules\Finance\Providers;

use App\Modules\Finance\Application\ConfirmedLossProjection;
use App\Modules\Finance\Application\Contracts\ConfirmedLossReader;
use Illuminate\Support\ServiceProvider;

final class FinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ConfirmedLossReader::class, ConfirmedLossProjection::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
    }
}
