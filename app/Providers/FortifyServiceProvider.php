<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditService;
use App\Support\Audit\AuditSource;
use App\Support\Audit\AuditWriteException;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
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
            $user = User::where('email', $request->email)->first();

            return $user && $user->is_active && Hash::check($request->password, $user->password) ? $user : null;
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        \Event::listen(Login::class, function (Login $event): void {
            /** @var User $user */
            $user = $event->user;
            try {
                app(AuditService::class)->record(
                    AuditActor::user($user, AuditSource::Fortify),
                    'auth.login',
                    User::class,
                    $user->getAuthIdentifier(),
                    after: ['state' => 'authenticated'],
                    reason: 'credentials_accepted',
                );
            } catch (AuditWriteException $exception) {
                request()->session()->invalidate();
                auth()->guard()->forgetUser();

                throw $exception;
            }
        });

        \Event::listen(Logout::class, function (Logout $event): void {
            /** @var User|null $user */
            $user = $event->user;
            $actor = $user
                ? AuditActor::user($user, AuditSource::Fortify)
                : AuditActor::system(AuditSource::Fortify);

            try {
                app(AuditService::class)->record(
                    $actor,
                    'auth.logout',
                    $user ? User::class : 'session',
                    $user?->getAuthIdentifier(),
                    before: ['state' => 'authenticated'],
                    after: ['state' => 'guest'],
                    reason: 'logout_requested',
                );
            } catch (AuditWriteException $exception) {
                request()->session()->invalidate();
                auth()->guard()->forgetUser();

                throw $exception;
            }
        });

        \Event::listen(Failed::class, function (Failed $event): void {
            /** @var User|null $user */
            $user = $event->user;
            $actor = $user
                ? AuditActor::user($user, AuditSource::Fortify)
                : AuditActor::system(AuditSource::Fortify);

            app(AuditService::class)->record(
                $actor,
                'auth.login_failed',
                $user ? User::class : 'credential',
                reason: 'credentials_rejected',
            );
        });

    }
}
