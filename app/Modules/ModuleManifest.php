<?php

namespace App\Modules;

use App\Modules\Audit\Providers\AuditServiceProvider;
use App\Modules\Customers\Providers\CustomersServiceProvider;
use App\Modules\Dashboard\Providers\DashboardServiceProvider;
use App\Modules\Identity\Providers\IdentityServiceProvider;
use App\Modules\Inventory\Providers\InventoryServiceProvider;
use App\Modules\Finance\Providers\FinanceServiceProvider;
use App\Modules\Logistics\Providers\LogisticsServiceProvider;
use App\Modules\Orders\Providers\OrdersServiceProvider;
use App\Modules\Runtime\Providers\RuntimeServiceProvider;
use App\Modules\Shell\Providers\ShellServiceProvider;
use App\Modules\Sync\Providers\SyncServiceProvider;
use App\Modules\Products\Providers\ProductsServiceProvider;
use App\Modules\Sync\Providers\ManualSyncServiceProvider;
use Illuminate\Support\ServiceProvider;
use LogicException;

final class ModuleManifest
{
    /**
     * @return list<class-string<ServiceProvider>>
     */
    public static function providers(): array
    {
        return [
            IdentityServiceProvider::class,
            OrdersServiceProvider::class,
            LogisticsServiceProvider::class,
            FinanceServiceProvider::class,
            RuntimeServiceProvider::class,
            AuditServiceProvider::class,
            SyncServiceProvider::class,
            ManualSyncServiceProvider::class,
            ShellServiceProvider::class,
            DashboardServiceProvider::class,
            CustomersServiceProvider::class,
            ProductsServiceProvider::class,
            InventoryServiceProvider::class,
        ];
    }

    /**
     * @param  list<class-string>  $providers
     * @return list<class-string<ServiceProvider>>
     */
    public static function validate(array $providers): array
    {
        foreach ($providers as $provider) {
            if (! class_exists($provider)) {
                throw new LogicException("Module Provider [{$provider}] does not exist.");
            }

            if (! is_a($provider, ServiceProvider::class, true)) {
                throw new LogicException("Module Provider [{$provider}] must extend ".ServiceProvider::class.'.');
            }
        }

        return $providers;
    }
}
