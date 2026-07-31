<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use App\Support\AuthorizationKeys;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(fn (User $user) => $user->hasPermission('super_admin') ? true : null);
        foreach (AuthorizationKeys::cases() as $key) {
            if (!str_contains($key->value, '.')) continue;
            Gate::define($key->value, fn (User $user) => $user->hasPermission($key->value));
        }
    }
}
