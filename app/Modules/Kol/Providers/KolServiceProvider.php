<?php

namespace App\Modules\Kol\Providers;

use App\Modules\Kol\Application\Contracts\AffiliateWpGateway;
use App\Modules\Kol\Experience\Console\Commands\AffiliateWpHealthCommand;
use App\Modules\Kol\Experience\Console\Commands\ApplyKolAffiliateBindingProductionCommand;
use App\Modules\Kol\Infrastructure\AffiliateWp\HttpAffiliateWpGateway;
use App\Modules\Kol\Infrastructure\AffiliateWp\LocalUatAffiliateWpGateway;
use Illuminate\Support\ServiceProvider;

final class KolServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $gateway = $this->app->environment(['local', 'testing']) && (bool) config('services.andy_core.uat_fake', false)
            ? LocalUatAffiliateWpGateway::class
            : HttpAffiliateWpGateway::class;

        $this->app->bind(AffiliateWpGateway::class, $gateway);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([AffiliateWpHealthCommand::class, ApplyKolAffiliateBindingProductionCommand::class]);
        }
    }
}
