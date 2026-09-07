<?php

namespace App\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ErpExceptionHandler
{
    public static function handleServerError(Throwable $e, Request $request): Response
    {
        $errorId = strtoupper((string) Str::uuid());
        $requestId = $request->attributes->get('audit_request_id');
        $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;

        Log::error('erp.server_error', [
            'error_id' => $errorId,
            'request_id' => $requestId,
            'status' => $status,
            'method' => $request->method(),
            'route' => $request->path(),
            'user_id' => $request->user()?->getAuthIdentifier(),
            'deploy_sha' => env('DEPLOY_SHA'),
            'query_count' => $request->attributes->get('erp_diag_query_count'),
            'query_time_ms' => $request->attributes->get('erp_diag_query_time_ms'),
            'duration_ms' => $request->attributes->get('erp_diag_duration_ms'),
            'peak_memory_kb' => $request->attributes->get('erp_diag_peak_memory_kb'),
            'exception_class' => get_class($e),
        ]);

        $diagnosticHeaders = [
            'X-Request-ID' => (string) ($requestId ?? ''),
            'X-Query-Count' => (string) ($request->attributes->get('erp_diag_query_count') ?? ''),
            'X-Query-Time-Ms' => (string) ($request->attributes->get('erp_diag_query_time_ms') ?? ''),
            'X-Server-Time-Ms' => (string) ($request->attributes->get('erp_diag_duration_ms') ?? ''),
        ];

        $isInertia = $request->header('X-Inertia') !== null;

        if ($isInertia) {
            return response()->json([
                'error_id' => $errorId,
                'message' => '服务器内部错误，请稍后重试。',
            ], $status)->withHeaders(array_merge($diagnosticHeaders, [
                'X-Inertia-Error-Id' => $errorId,
            ]));
        }

        return response()->view('errors.500', ['error_id' => $errorId], $status)
            ->withHeaders($diagnosticHeaders);
    }
}
