<?php

namespace App\Modules\Logistics\Providers;

use App\Modules\Logistics\Application\Contracts\CarrierBillingEvidenceReader;
use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use App\Modules\Logistics\Application\FulfillmentPlanCostEstimator;
use App\Modules\Logistics\Application\FulfillmentPlanPreviewService;
use App\Modules\Logistics\Application\WarehouseRoutingPolicy;
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
        $this->app->bind(FulfillmentPlanPreviewService::class, fn ($app): FulfillmentPlanPreviewService => new FulfillmentPlanPreviewService(
            $app->make(InventoryAvailabilityReader::class),
            $app->make(WarehouseRoutingPolicy::class),
            $app->make(FulfillmentPlanCostEstimator::class),
        ));
    }
}
