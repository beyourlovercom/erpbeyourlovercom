<?php

use App\Modules\Identity\Experience\Console\Commands\CreateErpUser;
use App\Console\Commands\RuntimeHealthCommand;
use App\Modules\Identity\Experience\Middleware\ApplyLocalAdminBypass;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        CreateErpUser::class,
        RuntimeHealthCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            ApplyLocalAdminBypass::class,
            HandleInertiaRequests::class,
        ]);
        $middleware->prependToPriorityList(
            AuthenticatesRequests::class,
            ApplyLocalAdminBypass::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
