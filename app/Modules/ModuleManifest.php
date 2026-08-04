<?php

namespace App\Modules;

use App\Modules\Identity\Providers\IdentityServiceProvider;
use Illuminate\Support\ServiceProvider;
use LogicException;

final class ModuleManifest
{
    /**
     * @return list<class-string<ServiceProvider>>
     */
    public static function providers(): array
    {
        return [IdentityServiceProvider::class];
    }

    /**
     * @param list<class-string> $providers
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
