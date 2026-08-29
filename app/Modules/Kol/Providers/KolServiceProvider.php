<?php

namespace App\Modules\Kol\Providers;

use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Experience\Console\Commands\AffiliateWpHealthCommand;
use App\Modules\Kol\Infrastructure\AffiliateWp\HttpAffiliateWpGateway;
use Illuminate\Support\ServiceProvider;

final class KolServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AffiliateWpGateway::class, HttpAffiliateWpGateway::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([AffiliateWpHealthCommand::class]);
        }
    }
}
