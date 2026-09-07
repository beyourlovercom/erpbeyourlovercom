<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\TestCase;

final class ServerErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/force-500', function () {
            throw new \RuntimeException('Synthetic 500 for testing');
        })->middleware('web');

        Route::get('/_test/force-503', function () {
            throw new ServiceUnavailableHttpException(null, 'Synthetic 503');
        })->middleware('web');
    }

    public function test_direct_500_renders_safe_html_without_stack_trace(): void
    {
        $response = $this->get('/_test/force-500');

        $response->assertStatus(500);
        $response->assertDontSee('RuntimeException');
        $response->assertDontSee('Synthetic 500 for testing');
        $response->assertSee('服务器内部错误');
    }

    public function test_direct_500_html_contains_error_id(): void
    {
        $response = $this->get('/_test/force-500');

        $response->assertStatus(500);
        $content = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}/i',
            $content,
        );
    }

    public function test_503_preserves_actual_status_code(): void
    {
        $response = $this->get('/_test/force-503');

        $response->assertStatus(503);
        $response->assertSee('服务器内部错误');
        $response->assertDontSee('Synthetic 503');
    }

    public function test_inertia_500_returns_json_with_error_id_and_preserves_status(): void
    {
        $response = $this->withHeaders(['X-Inertia' => 'true'])
            ->get('/_test/force-500');

        $response->assertStatus(500);
        $response->assertJsonStructure(['error_id', 'message']);
        $errorId = $response->json('error_id');
        $this->assertMatchesRegularExpression(
            '/[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}/i',
            $errorId,
        );
    }

    public function test_inertia_503_preserves_status(): void
    {
        $response = $this->withHeaders(['X-Inertia' => 'true'])
            ->get('/_test/force-503');

        $response->assertStatus(503);
        $response->assertJsonStructure(['error_id', 'message']);
    }

    public function test_500_emits_structured_log_with_status_and_safe_fields(): void
    {
        Log::spy();

        $this->get('/_test/force-500');

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $channel, array $context): bool {
                return $channel === 'erp.server_error'
                    && array_key_exists('error_id', $context)
                    && array_key_exists('request_id', $context)
                    && array_key_exists('status', $context)
                    && $context['status'] === 500
                    && array_key_exists('method', $context)
                    && array_key_exists('route', $context)
                    && ! array_key_exists('exception', $context)
                    && ! array_key_exists('trace', $context);
            });
    }

    public function test_503_log_records_correct_status(): void
    {
        Log::spy();

        $this->get('/_test/force-503');

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $channel, array $context): bool {
                return $channel === 'erp.server_error'
                    && ($context['status'] ?? null) === 503;
            });
    }

    public function test_thrown_request_diagnostics_are_non_null_in_log(): void
    {
        Log::spy();

        $this->get('/_test/force-500');

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $channel, array $context): bool {
                if ($channel !== 'erp.server_error') return false;
                // query_count, query_time_ms and duration_ms must be set (not null)
                // because RequestDiagnostics uses try/finally.
                return array_key_exists('query_count', $context)
                    && $context['query_count'] !== null
                    && array_key_exists('query_time_ms', $context)
                    && $context['query_time_ms'] !== null
                    && array_key_exists('duration_ms', $context)
                    && $context['duration_ms'] !== null;
            });
    }

    public function test_500_does_not_expose_exception_message_in_html(): void
    {
        $response = $this->get('/_test/force-500');

        $response->assertDontSee('RuntimeException');
        $response->assertDontSee('Synthetic 500 for testing');
        $response->assertDontSee('app/Http');
        $response->assertDontSee('#0');
    }

    public function test_5xx_response_carries_diagnostic_headers(): void
    {
        $response = $this->get('/_test/force-500');

        $response->assertStatus(500);
        $response->assertHeader('X-Request-ID');
        $response->assertHeader('X-Query-Count');
        $response->assertHeader('X-Query-Time-Ms');
        $response->assertHeader('X-Server-Time-Ms');
    }
}
