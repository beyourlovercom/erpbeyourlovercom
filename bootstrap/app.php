<?php

use App\Modules\Identity\Experience\Console\Commands\CreateErpUser;
use App\Modules\Logistics\Experience\Console\Commands\AutomaticAllocationCommand;
use App\Modules\Runtime\Experience\Console\Commands\RuntimeHealthCommand;
use App\Modules\Identity\Experience\Middleware\ApplyLocalAdminBypass;
use App\Modules\Identity\Experience\Middleware\EnsureActiveAuthenticatedUser;
use App\Modules\Identity\Experience\Middleware\RequireAnyPermission;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequestDiagnostics;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use App\Http\ErpExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Throwable;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        CreateErpUser::class,
        RuntimeHealthCommand::class,
        AutomaticAllocationCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [
            RequestDiagnostics::class,
        ]);
        $middleware->web(append: [
            ApplyLocalAdminBypass::class,
            EnsureActiveAuthenticatedUser::class,
            HandleInertiaRequests::class,
        ]);
        $middleware->alias(['any.permission' => RequireAnyPermission::class]);
        $middleware->prependToPriorityList(
            AuthenticatesRequests::class,
            ApplyLocalAdminBypass::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        $exceptions->render(function (Throwable $e, Request $request) {
            $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
            if ($status >= 500 && ! $request->is('api/*') && ! $request->expectsJson()) {
                return ErpExceptionHandler::handleServerError($e, $request);
            }
        });
    })->create();
