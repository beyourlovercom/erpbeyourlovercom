<?php

namespace App\Modules\Logistics\Providers;

use App\Modules\Logistics\Application\Contracts\CarrierBillingEvidenceReader;
use App\Modules\Logistics\Infrastructure\EloquentCarrierBillingEvidenceReader;
use Illuminate\Support\ServiceProvider;

final class LogisticsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');
    }

    public function register(): void
    {
        $this->app->bind(CarrierBillingEvidenceReader::class, EloquentCarrierBillingEvidenceReader::class);
    }
}
