<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ErpRequestObservabilityTest extends TestCase
{
    public function test_valid_request_id_is_reused_and_returned(): void
    {
        Route::get('/testing/observability', fn () => response()->json(['ok' => true]));

        $this->get('/testing/observability', ['X-ERP-Request-ID' => 'test-request-123'])
            ->assertOk()
            ->assertHeader('X-ERP-Request-ID', 'test-request-123');
    }

    public function test_inertia_500_is_safe_and_contains_error_id(): void
    {
        config()->set('app.debug', false);
        Route::get('/testing/inertia-error', fn () => throw new \RuntimeException('secret exception details'));

        $response = $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'text/html, application/xhtml+xml',
            'X-ERP-Request-ID' => 'test-inertia-500',
        ])->get('/testing/inertia-error');

        $response->assertStatus(500)
            ->assertHeader('X-ERP-Request-ID', 'test-inertia-500')
            ->assertJsonPath('component', 'Errors/ServerError')
            ->assertJsonPath('props.errorId', 'test-inertia-500')
            ->assertJsonMissing(['message' => 'secret exception details']);
    }

    public function test_non_500_http_status_is_not_rewritten_as_inertia_500(): void
    {
        config()->set('app.debug', false);
        Route::get('/testing/not-found', fn () => abort(404));

        $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'text/html, application/xhtml+xml',
        ])->get('/testing/not-found')->assertNotFound();
    }
}
