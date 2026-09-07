<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class RequestDiagnostics
{
    private const ATTRIBUTE = 'audit_request_id';

    public function handle(Request $request, Closure $next): Response
    {
        $this->resolveRequestId($request);

        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        $queryCount = 0;
        $queryTimeMs = 0.0;

        DB::listen(static function ($query) use (&$queryCount, &$queryTimeMs): void {
            $queryCount++;
            $queryTimeMs += $query->time;
        });

        try {
            $response = $next($request);
        } finally {
            // Populate request attributes unconditionally so the 5xx handler
            // sees non-null diagnostics even when next() throws.
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);
            $peakMemoryKb = (int) round((memory_get_peak_usage(true) - $startMemory) / 1024);

            $request->attributes->set('erp_diag_query_count', $queryCount);
            $request->attributes->set('erp_diag_query_time_ms', (int) round($queryTimeMs));
            $request->attributes->set('erp_diag_duration_ms', $durationMs);
            $request->attributes->set('erp_diag_peak_memory_kb', $peakMemoryKb);
        }

        $response->headers->set('X-Request-ID', $request->attributes->get(self::ATTRIBUTE));
        $response->headers->set('X-Query-Count', (string) $queryCount);
        $response->headers->set('X-Query-Time-Ms', (string) ((int) round($queryTimeMs)));
        $response->headers->set('X-Server-Time-Ms', (string) $durationMs);

        return $response;
    }

    private function resolveRequestId(Request $request): string
    {
        $existing = $request->attributes->get(self::ATTRIBUTE);
        if (is_string($existing) && Str::isUuid($existing)) {
            return $existing;
        }

        $header = $request->header('X-Request-ID');
        $requestId = is_string($header) && Str::isUuid($header)
            ? $header
            : (string) Str::uuid();

        $request->attributes->set(self::ATTRIBUTE, $requestId);

        return $requestId;
    }
}
