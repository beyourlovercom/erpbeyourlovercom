<?php

namespace App\Providers;

use App\Http\Middleware\ApplyLocalAdminBypass;
use App\Models\User;
use App\Support\Audit\AuditWriter;
use App\Support\Audit\EloquentAuditWriter;
use App\Support\AuthorizationKeys;
use Illuminate\Http\Request;
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
        Gate::before(function (User $user): ?bool {
            if ($user->hasPermission(AuthorizationKeys::SuperAdmin->value)) {
                return true;
            }

            if (! app()->bound('request')) {
                return null;
            }

            $request = app('request');
            if (! $request instanceof Request) {
                return null;
            }

            return app()->environment('local')
                && config('erp_local.admin_bypass.enabled') === true
                && $request->attributes->get(ApplyLocalAdminBypass::REQUEST_MARKER) === true
                && $request->user()?->is($user)
                    ? true
                    : null;
        });
        foreach (AuthorizationKeys::cases() as $key) {
            if (! str_contains($key->value, '.')) {
                continue;
            }
            Gate::define($key->value, fn (User $user) => $user->hasPermission($key->value));
        }
    }
}
