<?php

namespace Tests\Feature\Marketing;

use App\Modules\Marketing\Infrastructure\AndyCore\HttpWordPressSubscriberGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HttpWordPressSubscriberGatewayTest extends TestCase
{
    private const BaseUrl = 'https://wp.test';
    private const ConnectionKey = 'beyourlover.com';
    private const KeyId = 'key-001';
    private const SharedSecret = 'test-shared-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.env' => 'testing',
            'services.andy_core.verify_tls' => true,
            'services.andy_core.url' => self::BaseUrl,
            'services.andy_core.connection_key' => self::ConnectionKey,
            'services.andy_core.key_id' => self::KeyId,
            'services.andy_core.shared_secret' => self::SharedSecret,
        ]);
    }

    public function test_tls_verification_is_enabled_by_default(): void
    {
        $this->assertTrue((bool) config('services.andy_core.verify_tls'));
        $this->fakeSuccessfulSnapshot();

        app(HttpWordPressSubscriberGateway::class)->snapshotSubscribers();
        $this->assertTrue($this->tlsVerificationDecision());
    }

    public function test_local_explicit_false_can_disable_tls_verification(): void
    {
        config(['app.env' => 'local', 'services.andy_core.verify_tls' => false]);
        $this->fakeSuccessfulSnapshot();

        app(HttpWordPressSubscriberGateway::class)->snapshotSubscribers();
        $this->assertFalse($this->tlsVerificationDecision());
    }

    public function test_production_cannot_disable_tls_verification(): void
    {
        config(['app.env' => 'production', 'services.andy_core.verify_tls' => false]);
        $this->fakeSuccessfulSnapshot();

        app(HttpWordPressSubscriberGateway::class)->snapshotSubscribers();
        $this->assertTrue($this->tlsVerificationDecision());
    }

    public function test_subscriber_snapshot_uses_exact_signed_path_and_query_contract(): void
    {
        Http::fake(['*' => Http::response($this->envelope([
            'items' => [], 'next_cursor' => null, 'source_site' => self::ConnectionKey,
        ]), 200)]);

        app(HttpWordPressSubscriberGateway::class)->snapshotSubscribers('cursor-abc', '2026-08-29T00:00:00Z', 50);

        Http::assertSent(function (Request $request): bool {
            $parts = parse_url($request->url());
            parse_str($parts['query'] ?? '', $query);
            $pathWithQuery = ($parts['path'] ?? '').(($parts['query'] ?? '') !== '' ? '?'.$parts['query'] : '');
            $timestamp = $request->header('X-YBY-Timestamp')[0] ?? '';
            $nonce = $request->header('X-YBY-Nonce')[0] ?? '';
            $connectionKey = $request->header('X-YBY-Connection-Key')[0] ?? '';
            $canonical = implode("\n", ['GET', $pathWithQuery, $timestamp, $nonce, $connectionKey, '', hash('sha256', '')]);
            $expected = hash_hmac('sha256', $canonical, self::SharedSecret);

            return ($parts['path'] ?? '') === '/wp-json/andy-core/v1/erp/snapshot/subscribers'
                && $query === ['cursor' => 'cursor-abc', 'updated_after' => '2026-08-29T00:00:00Z', 'limit' => '50']
                && $request->header('X-YBY-Key-Id')[0] === self::KeyId
                && $request->header('X-YBY-Signature-Version')[0] === 'v1'
                && ! $request->hasHeader('X-YBY-Idempotency-Key')
                && hash_equals($expected, $request->header('X-YBY-Signature')[0] ?? '');
        });
    }

    public function test_subscriber_snapshot_uses_origin_compatible_connector_user_agent(): void
    {
        $this->fakeSuccessfulSnapshot();

        app(HttpWordPressSubscriberGateway::class)->snapshotSubscribers();

        Http::assertSent(function (Request $request): bool {
            $userAgent = $request->header('User-Agent')[0] ?? '';

            return str_starts_with($userAgent, 'Mozilla/5.0')
                && str_contains($userAgent, 'BYL-ERP-Marketing-WordPress-Connector/1.0');
        });
    }

    public function test_snapshot_rejects_connection_key_mismatch(): void
    {
        Http::fake(['*' => Http::response([
            'ok' => true, 'contract_version' => '1', 'request_id' => 'req-1',
            'connection_key' => 'wrong.example', 'data' => ['items' => []],
        ], 200)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid response envelope');
        app(HttpWordPressSubscriberGateway::class)->snapshotSubscribers();
    }


    public function test_native_source_and_cursor_are_signed_as_exact_path_and_query(): void
    {
        config(['services.andy_core.newsletter_snapshot_enabled' => true]);
        Http::fake(['*' => Http::response($this->envelope([
            'source' => 'andy_core_newsletter', 'items' => [], 'next_cursor' => null,
            'source_site' => self::ConnectionKey,
        ]), 200)]);

        app(HttpWordPressSubscriberGateway::class)->snapshotSubscribers(
            'native-cursor', '2026-09-01T00:00:00Z', 100, 'andy_core_newsletter'
        );
        Http::assertSent(function (Request $request): bool {
            $parts = parse_url($request->url());
            $path = ($parts['path'] ?? '').'?'.($parts['query'] ?? '');
            parse_str($parts['query'] ?? '', $query);
            $canonical = implode("\n", [
                'GET', $path,
                $request->header('X-YBY-Timestamp')[0] ?? '',
                $request->header('X-YBY-Nonce')[0] ?? '',
                self::ConnectionKey, '', hash('sha256', ''),
            ]);
            return $query === [
                'source' => 'andy_core_newsletter', 'cursor' => 'native-cursor',
                'updated_after' => '2026-09-01T00:00:00Z', 'limit' => '100',
            ] && hash_equals(
                hash_hmac('sha256', $canonical, self::SharedSecret),
                $request->header('X-YBY-Signature')[0] ?? ''
            );
        });
    }

    public function test_native_snapshot_is_denied_by_default_until_core_contract_freeze(): void
    {
        config(['services.andy_core.newsletter_snapshot_enabled' => false]);
        Http::fake();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not enabled');
        app(HttpWordPressSubscriberGateway::class)->snapshotSubscribers(source: 'andy_core_newsletter');
        Http::assertNothingSent();
    }

    public function test_old_core_endpoint_cannot_be_misread_as_native_response(): void
    {
        config(['services.andy_core.newsletter_snapshot_enabled' => true]);
        // A legacy Core instance can return valid HMAC envelope but ignores source.
        $this->fakeSuccessfulSnapshot();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('wrong subscriber source');
        app(HttpWordPressSubscriberGateway::class)->snapshotSubscribers(source: 'andy_core_newsletter');
    }

    public function test_native_http_503_redirect_and_invalid_cursor_fail_closed(): void
    {
        config(['services.andy_core.newsletter_snapshot_enabled' => true]);
        foreach ([503, 302] as $status) {
            Http::fake(['*' => Http::response([], $status)]);
            try {
                app(HttpWordPressSubscriberGateway::class)->snapshotSubscribers(source: 'andy_core_newsletter');
                $this->fail('Provider failure should throw.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('HTTP '.$status, $exception->getMessage());
            }
        }
        Http::fake(['*' => Http::response($this->envelope([
            'source' => 'andy_core_newsletter', 'items' => [],
            'next_cursor' => ['malformed'], 'source_site' => self::ConnectionKey,
        ]), 200)]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid cursor');
        app(HttpWordPressSubscriberGateway::class)->snapshotSubscribers(source: 'andy_core_newsletter');
    }

    private function envelope(array $data): array
    {
        return [
            'ok' => true,
            'contract_version' => '1',
            'request_id' => 'req-001',
            'connection_key' => self::ConnectionKey,
            'data' => $data,
        ];
    }

    private function fakeSuccessfulSnapshot(): void
    {
        Http::fake(['*' => Http::response($this->envelope([
            'items' => [], 'next_cursor' => null, 'source_site' => self::ConnectionKey,
        ]), 200)]);
    }

    private function tlsVerificationDecision(): bool
    {
        $method = new \ReflectionMethod(HttpWordPressSubscriberGateway::class, 'shouldVerifyTls');
        $method->setAccessible(true);

        return $method->invoke(app(HttpWordPressSubscriberGateway::class));
    }
}
