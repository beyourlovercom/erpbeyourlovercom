<?php

namespace App\Modules\Logistics\Experience\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireErp2ReadonlyProjection
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app()->environment(['local', 'testing']) && config('erp2_readonly.enabled', false), 404);

        return $next($request);
    }
}
