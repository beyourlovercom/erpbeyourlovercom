<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Audit\AuditWriter;
use App\Support\Audit\EloquentAuditWriter;
use App\Support\AuthorizationKeys;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AuditWriter::class, EloquentAuditWriter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(fn (User $user) => $user->hasPermission('super_admin') ? true : null);
        foreach (AuthorizationKeys::cases() as $key) {
            if (! str_contains($key->value, '.')) {
                continue;
            }
            Gate::define($key->value, fn (User $user) => $user->hasPermission($key->value));
        }
    }
}
