<?php

namespace App\Modules\Marketing\Infrastructure\AndyCore;

use App\Modules\Marketing\Application\Contracts\WordPressSubscriberGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class HttpWordPressSubscriberGateway implements WordPressSubscriberGateway
{
    private const string Namespace = '/wp-json/andy-core/v1/erp';
    private const int MaximumLimit = 100;

    public function descriptor(): array { return ['configured' => $this->configured(), 'url' => $this->baseUrl(), 'connection_key' => $this->connectionKey()]; }

    public function snapshotSubscribers(?string $cursor = null, ?string $updatedAfter = null, int $limit = 100, ?string $source = null): array
    {
        if (! $this->configured()) throw new RuntimeException('Andy Core WordPress connector is not configured.');
        if ($source !== null && $source !== 'andy_core_newsletter') {
            throw new RuntimeException('Unsupported WordPress subscriber snapshot source.');
        }
        if ($source === 'andy_core_newsletter' && ! (bool) config('services.andy_core.newsletter_snapshot_enabled', false)) {
            throw new RuntimeException('Andy Core native Newsletter snapshot contract is not enabled.');
        }
        if ($cursor !== null && (trim($cursor) === '' || strlen($cursor) > 1024)) {
            throw new RuntimeException('Invalid subscriber snapshot cursor.');
        }
        if ($updatedAfter !== null && ! preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:Z|[+-]\\d{2}:\\d{2})$/', $updatedAfter)) {
            throw new RuntimeException('Invalid subscriber updated_after timestamp.');
        }
        // Source is part of the URL BEFORE signing; null retains exact Elementor legacy path.
        $query = array_filter(['source' => $source, 'cursor' => $cursor, 'updated_after' => $updatedAfter, 'limit' => min(self::MaximumLimit, max(1, $limit))], fn ($value) => $value !== null);
        $path = self::Namespace.'/snapshot/subscribers?'.http_build_query($query);
        [$timestamp, $nonce] = [(string) CarbonImmutable::now('UTC')->getTimestamp(), Str::uuid()->toString()];
        $canonical = implode("\n", ['GET', $path, $timestamp, $nonce, $this->connectionKey(), '', hash('sha256', '')]);
        $headers = ['X-YBY-Key-Id' => $this->keyId(), 'X-YBY-Connection-Key' => $this->connectionKey(), 'X-YBY-Timestamp' => $timestamp, 'X-YBY-Nonce' => $nonce, 'X-YBY-Signature-Version' => 'v1', 'X-YBY-Signature' => hash_hmac('sha256', $canonical, $this->secret()), 'User-Agent' => 'Mozilla/5.0 BYL-ERP-Marketing-WordPress-Connector/1.0'];
        $request = Http::withHeaders($headers)
            ->acceptJson()
            ->timeout(15)
            ->connectTimeout(5)
            ->withoutRedirecting()
            ->withOptions(['verify' => true]);
        if (! $this->shouldVerifyTls()) {
            $request->withoutVerifying();
        }
        try { $response = $request->get($this->baseUrl().$path); }
        catch (ConnectionException $exception) { throw new RuntimeException('Andy Core WordPress connector unavailable.', 0, $exception); }
        if ($response->redirect() || $response->serverError() || $response->clientError()) throw new RuntimeException('Andy Core WordPress connector returned HTTP '.$response->status().'.');
        $raw = $response->json();
        if (! is_array($raw) || ($raw['ok'] ?? null) !== true || ($raw['contract_version'] ?? null) !== '1' || ! is_string($raw['request_id'] ?? null) || $raw['request_id'] === '' || ($raw['connection_key'] ?? null) !== $this->connectionKey()) throw new RuntimeException('Andy Core WordPress connector returned an invalid response envelope.');
        $data = $raw['data'] ?? [];
        if (! is_array($data) || ! is_array($data['items'] ?? null)) throw new RuntimeException('Andy Core WordPress connector returned invalid subscriber data.');
        // Core native endpoint is pending a cross-repository freeze. Require a source echo
        // so that an outdated Core server cannot silently return legacy Elementor items.
        if ($source !== null && ($data['source'] ?? null) !== $source) {
            throw new RuntimeException('Andy Core WordPress connector returned the wrong subscriber source.');
        }
        $next = $data['next_cursor'] ?? null;
        if ($next !== null && (! is_string($next) || $next === '' || strlen($next) > 1024)) {
            throw new RuntimeException('Andy Core WordPress connector returned an invalid cursor.');
        }
        return ['items' => array_values($data['items']), 'next_cursor' => $next, 'source_site' => isset($data['source_site']) ? (string) $data['source_site'] : $this->connectionKey(), 'request_id' => $raw['request_id'], 'source' => $source];
    }

    private function configured(): bool { return $this->baseUrl() !== '' && $this->keyId() !== '' && $this->secret() !== ''; }
    private function baseUrl(): string
    {
        $raw = rtrim(trim((string) config('services.andy_core.url')), '/'); if ($raw === '') return '';
        $parsed = parse_url($raw);
        if ($parsed === false || empty($parsed['host']) || ! in_array($parsed['scheme'] ?? '', ['http', 'https'], true) || ! empty($parsed['user']) || ! empty($parsed['pass']) || ! empty($parsed['query']) || ! empty($parsed['fragment']) || (! empty($parsed['path']) && $parsed['path'] !== '/') || (! in_array(config('app.env', 'production'), ['local', 'testing'], true) && ($parsed['scheme'] ?? '') !== 'https')) return '';
        return $parsed['scheme'].'://'.$parsed['host'].(! empty($parsed['port']) ? ':'.$parsed['port'] : '');
    }
    private function connectionKey(): string { return trim((string) config('services.andy_core.connection_key', 'beyourlover.com')) ?: 'beyourlover.com'; }
    private function keyId(): string { return trim((string) config('services.andy_core.key_id')); }
    private function secret(): string { return (string) config('services.andy_core.shared_secret'); }
    private function shouldVerifyTls(): bool
    {
        return ! in_array(config('app.env', 'production'), ['local', 'testing'], true)
            || (bool) config('services.andy_core.verify_tls', true);
    }
}
