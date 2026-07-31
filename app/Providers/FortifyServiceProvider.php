<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Hash;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Failed;
use App\Models\AuditRecord;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
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
        Fortify::loginView(fn () => inertia('Auth/Login'));
        Fortify::authenticateUsing(function ($request) {
            $user = \App\Models\User::where('email', $request->email)->first();
            return $user && $user->is_active && Hash::check($request->password, $user->password) ? $user : null;
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        \Event::listen(Login::class, fn (Login $event) => AuditRecord::create(['actor_id' => $event->user->getAuthIdentifier(), 'action' => 'auth.login', 'entity_type' => get_class($event->user), 'entity_id' => (string) $event->user->getAuthIdentifier(), 'source' => 'fortify', 'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(), 'created_at' => now()]));
        \Event::listen(Logout::class, fn (Logout $event) => AuditRecord::create(['actor_id' => $event->user?->getAuthIdentifier(), 'action' => 'auth.logout', 'entity_type' => $event->user ? get_class($event->user) : 'session', 'entity_id' => $event->user ? (string) $event->user->getAuthIdentifier() : null, 'source' => 'fortify', 'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(), 'created_at' => now()]));
        \Event::listen(Failed::class, fn (Failed $event) => AuditRecord::create(['actor_id' => $event->user?->getAuthIdentifier(), 'action' => 'auth.login_failed', 'entity_type' => $event->user ? get_class($event->user) : 'credential', 'entity_id' => null, 'source' => 'fortify', 'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(), 'created_at' => now()]));

    }
}
