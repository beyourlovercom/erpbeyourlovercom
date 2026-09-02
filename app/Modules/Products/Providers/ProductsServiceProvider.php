<?php

namespace App\Modules\Products\Providers;

use App\Modules\Products\Application\Contracts\ProductExternalReferenceRepository;
use App\Modules\Products\Application\Contracts\ProductRepository;
use App\Modules\Products\Application\Contracts\SkuAliasRepository;
use App\Modules\Products\Application\Contracts\SkuReconciliationRepository;
use App\Modules\Products\Application\Contracts\WeightEvidenceRepository;
use App\Modules\Products\Experience\Console\ImportWooCsvCommand;
use App\Modules\Products\Infrastructure\EloquentProductExternalReferenceRepository;
use App\Modules\Products\Infrastructure\EloquentProductRepository;
use App\Modules\Products\Infrastructure\EloquentSkuAliasRepository;
use App\Modules\Products\Infrastructure\EloquentSkuReconciliationRepository;
use App\Modules\Products\Infrastructure\EloquentWeightEvidenceRepository;
use Illuminate\Support\ServiceProvider;

final class ProductsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProductRepository::class, EloquentProductRepository::class);
        $this->app->bind(SkuAliasRepository::class, EloquentSkuAliasRepository::class);
        $this->app->bind(SkuReconciliationRepository::class, EloquentSkuReconciliationRepository::class);
        $this->app->bind(WeightEvidenceRepository::class, EloquentWeightEvidenceRepository::class);
        $this->app->bind(ProductExternalReferenceRepository::class, EloquentProductExternalReferenceRepository::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([ImportWooCsvCommand::class]);
        }
    }
}
