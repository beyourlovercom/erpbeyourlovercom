<?php

namespace App\Modules\Orders\Providers;

use App\Modules\Orders\Application\Contracts\WooCommerceFinancialEvidenceReader;
use App\Modules\Orders\Application\Contracts\WooCommerceOrderGateway;
use App\Modules\Orders\Application\OrderWorkflowStatusDisplayCatalog;
use App\Modules\Orders\Experience\Console\PaymentHealthPreviewCommand;
use App\Modules\Orders\Experience\Console\PreviewStalePaymentsCommand;
use App\Modules\Orders\Experience\Console\RepairWooOrderIdentitiesCommand;
use App\Modules\Orders\Experience\Console\RepairCollaborationOrderIdentitiesCommand;
use App\Modules\Orders\Experience\Console\SyncWooCommerceOrdersCommand;
use App\Modules\Orders\Infrastructure\WooCommerce\EloquentWooCommerceFinancialEvidenceReader;
use App\Modules\Orders\Infrastructure\WooCommerce\HttpWooCommerceOrderGateway;
use Illuminate\Support\ServiceProvider;

final class OrdersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WooCommerceOrderGateway::class, HttpWooCommerceOrderGateway::class);
        $this->app->bind(WooCommerceFinancialEvidenceReader::class, EloquentWooCommerceFinancialEvidenceReader::class);
        $this->app->scoped(OrderWorkflowStatusDisplayCatalog::class);
        $this->app->scoped(\App\Modules\Orders\Application\OrderContactProjection::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Experience/Routes/web.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncWooCommerceOrdersCommand::class,
                PreviewStalePaymentsCommand::class,
                PaymentHealthPreviewCommand::class,
                RepairWooOrderIdentitiesCommand::class,
                RepairCollaborationOrderIdentitiesCommand::class,
            ]);
        }
    }
}
