<?php

namespace App\Modules\Finance\Providers;

use App\Modules\Finance\Application\ConfirmedLossProjection;
use App\Modules\Finance\Application\Contracts\ConfirmedLossReader;
use App\Modules\Finance\Application\Contracts\FxManualRateRepository;
use App\Modules\Finance\Application\Contracts\WooFinancialBootstrapOrderStore;
use App\Modules\Finance\Infrastructure\EloquentFxManualRateRepository;
use App\Modules\Finance\Infrastructure\EloquentWooFinancialBootstrapOrderStore;
use App\Modules\Finance\Experience\Console\Commands\BootstrapWooFinancialEvidenceCommand;
use App\Modules\Finance\Experience\Console\Commands\MaterializeFixedCostsCommand;
use App\Modules\Finance\Experience\Console\Commands\MaterializeOrderCostsCommand;
use App\Modules\Finance\Experience\Console\Commands\MaterializePaymentFeesCommand;
use App\Modules\Finance\Experience\Console\Commands\MaterializeProductCostsCommand;
use Illuminate\Support\ServiceProvider;

final class FinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ConfirmedLossReader::class, ConfirmedLossProjection::class);
        $this->app->bind(FxManualRateRepository::class, EloquentFxManualRateRepository::class);
        $this->app->bind(WooFinancialBootstrapOrderStore::class, EloquentWooFinancialBootstrapOrderStore::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
        if ($this->app->runningInConsole()) {
            $this->commands([MaterializeOrderCostsCommand::class, MaterializeFixedCostsCommand::class, MaterializeProductCostsCommand::class, MaterializePaymentFeesCommand::class, BootstrapWooFinancialEvidenceCommand::class]);
        }
    }
}
