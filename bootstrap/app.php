<?php

use App\Modules\Identity\Experience\Console\Commands\CreateErpUser;
use App\Modules\Identity\Experience\Console\Commands\StaffAccessReadinessAuditCommand;
use App\Modules\Logistics\Experience\Console\Commands\AutomaticAllocationCommand;
use App\Modules\Logistics\Experience\Console\Commands\BackfillDomesticCarrierDefaultsCommand;
use App\Modules\Logistics\Experience\Console\Commands\ShipOutProductionCanaryOperatorCommand;
use App\Modules\Logistics\Experience\Console\Commands\RepairAllocatedGiftSkuCommand;
use App\Modules\Logistics\Experience\Console\Commands\RepairWarehousePhysicalSkuCommand;
use App\Modules\Runtime\Experience\Console\Commands\RuntimeHealthCommand;
use App\Modules\Identity\Experience\Middleware\ApplyLocalAdminBypass;
use App\Modules\Identity\Experience\Middleware\EnsureActiveAuthenticatedUser;
use App\Modules\Identity\Experience\Middleware\RequireAnyPermission;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ObserveErpRequest;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        CreateErpUser::class,
        StaffAccessReadinessAuditCommand::class,
        RuntimeHealthCommand::class,
        AutomaticAllocationCommand::class,
        BackfillDomesticCarrierDefaultsCommand::class,
        ShipOutProductionCanaryOperatorCommand::class,
        RepairAllocatedGiftSkuCommand::class,
        RepairWarehousePhysicalSkuCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Preserve operator-supplied account identifiers exactly; do not trim them.
        TrimStrings::except(['wordpress_username', 'affiliate_name', 'affiliate_url_slug']);
        $middleware->web(append: [
            ApplyLocalAdminBypass::class,
            EnsureActiveAuthenticatedUser::class,
            HandleInertiaRequests::class,
        ]);
        $middleware->prepend(ObserveErpRequest::class);
        $middleware->alias(['any.permission' => RequireAnyPermission::class]);
        $middleware->prependToPriorityList(
            AuthenticatesRequests::class,
            ApplyLocalAdminBypass::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            // File-upload actions opt in to JSON so the UI can show inline/Island
            // Chinese feedback instead of receiving a full HTML error page.
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (\Throwable $exception, Request $request) {
            // Keep Laravel's native validation, authentication and authorization
            // responses. These are expected client errors, not server outages.
            // In particular, Inertia validation failures must redirect back with
            // session errors rather than rendering Errors/ServerError as HTTP 500.
            if ($exception instanceof \Illuminate\Validation\ValidationException
                || $exception instanceof \Illuminate\Auth\AuthenticationException
                || $exception instanceof \Illuminate\Auth\Access\AuthorizationException
                || $exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return null;
            }

            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;

            // Preserve HTTP status, but provide an explicit business-safe feedback contract.
            // A normal Inertia location/redirect response is not an exception and never enters here.
            if ($status === 409 && $request->header('X-Inertia') && ! $request->expectsJson()) {
                $message = $exception->getMessage();
                // Only user-facing Chinese business reasons; never expose a trace, SQL, or raw HTML.
                $safe = preg_match('/\\p{Han}/u', $message)
                    && ! preg_match('/[<>]|SQLSTATE|password|secret|token|stack trace|\\bselect\\b/i', $message)
                    ? mb_substr($message, 0, 500)
                    : '业务状态与提交条件不一致，操作未确认。';
                return response()->json(['erp_feedback' => true, 'message' => $safe], 409);
            }
            if ($request->expectsJson() || ! $request->header('X-Inertia') || config('app.debug') || $status < 500) {
                return null;
            }

            $errorId = (string) $request->attributes->get('erp_request_id');

            $response = \Inertia\Inertia::render('Errors/ServerError', [
                'errorId' => $errorId,
            ])->toResponse($request);
            $response->setStatusCode($status);
            $response->headers->set((string) config('observability.request_id_header'), $errorId);

            return $response;
        });
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response): \Symfony\Component\HttpFoundation\Response {
            $request = request();
            $status = $response->getStatusCode();
            $requestId = $request->attributes->get('erp_request_id');
            // A direct document visit has no existing shell to preserve.
            // Use a standalone Inertia view with the same feedback island, never a fake success.
            if (in_array($status, [403, 404, 409], true)
                && $request->isMethod('GET') && ! $request->expectsJson()
                && ! $request->is('api/*') && ! $request->header('X-Inertia')
                && str_contains((string) $request->header('Accept'), 'text/html')) {
                $response = \Inertia\Inertia::render('Errors/HttpFeedback', [
                    'status' => $status, 'errorId' => (string) $requestId,
                ])->toResponse($request);
                $response->setStatusCode($status);
                $response->headers->set('Cache-Control', 'no-store, private');
            }
            if (is_string($requestId) && $requestId !== '') {
                $response->headers->set((string) config('observability.request_id_header'), $requestId);
            }

            return $response;
        });
    })->create();
