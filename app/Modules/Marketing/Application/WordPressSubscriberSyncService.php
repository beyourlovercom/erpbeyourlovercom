<?php

namespace App\Modules\Marketing\Application;

use App\Modules\Marketing\Application\Contracts\MarketingSubscriberSyncWriter;
use App\Modules\Marketing\Application\Contracts\WordPressSubscriberGateway;
use App\Modules\Sync\Application\SyncRunItemStatus;
use App\Modules\Sync\Application\SyncRunService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Throwable;

final class WordPressSubscriberSyncService
{
    private const int PageLimit = 100;
    private const int MaximumPages = 100;
    private const string NativeSource = 'andy_core_newsletter';

    public function __construct(
        private readonly WordPressSubscriberGateway $gateway,
        private readonly MarketingSubscriberSyncWriter $writer,
        private readonly SyncRunService $runs
    ) {}

    // Incremental updated_after is operator-supplied, never inferred from unrelated source cursors.
    // The legacy default remains unchanged until Core's signed native contract is frozen.
    public function sync(?Authenticatable $actor, ?string $updatedAfter = null): Model
    {
        if ($updatedAfter !== null) {
            $this->timestamp($updatedAfter, 'updated_after');
        }
        $descriptor = $this->gateway->descriptor();
        $connection = $descriptor['connection_key'] ?: null;
        $nativeEnabled = (bool) config('services.andy_core.newsletter_snapshot_enabled', false);
        $run = $this->runs->start(
            'wordpress_subscribers_snapshot', 0, $actor, 'wordpress', $connection,
            ['domain' => 'marketing', 'resource' => 'subscribers',
                'sources' => $nativeEnabled ? ['elementor_legacy', self::NativeSource] : ['elementor_legacy'],
                'updated_after' => $updatedAfter]
        );

        try {
            foreach ($nativeEnabled ? [null, self::NativeSource] : [null] as $source) {
                // Each stream owns independent page limits/cursors. Never resume the native
                // stream with a cursor from the legacy Elementor source, or vice versa.
                $cursor = null;
                $seenCursors = [];
                $pages = 0;
                do {
                    if (++$pages > self::MaximumPages) {
                        throw new RuntimeException('WordPress subscriber snapshot exceeded the page safety limit.');
                    }
                    $page = $this->gateway->snapshotSubscribers($cursor, $updatedAfter, self::PageLimit, $source);
                    if (! is_array($page) || ! is_array($page['items'] ?? null)
                        || ! array_key_exists('next_cursor', $page)) {
                        throw new RuntimeException('WordPress subscriber snapshot returned malformed pagination.');
                    }
                    if ($source !== null && ($page['source'] ?? null) !== $source) {
                        throw new RuntimeException('WordPress subscriber snapshot source does not match the requested source.');
                    }
                    $sourceSite = $page['source_site'] ?? $connection;
                    if (! is_string($sourceSite) || ! $this->validSite($sourceSite)) {
                        throw new RuntimeException('WordPress subscriber snapshot returned invalid source_site.');
                    }
                    foreach ($page['items'] as $item) {
                        $reference = is_array($item) && is_string($item['external_subscription_id'] ?? null)
                            ? mb_substr($item['external_subscription_id'], 0, 200) : null;
                        $runItem = $this->runs->addItem($run, [
                            'operation' => 'wordpress.subscriber_import',
                            'provider_reference' => $reference,
                        ]);
                        $this->runs->markProcessing($runItem);
                        try {
                            if (! is_array($item)) {
                                throw new \InvalidArgumentException('Subscriber item must be an object.');
                            }
                            $this->importItem($item, $sourceSite, $source);
                            $this->runs->finishItem($runItem, SyncRunItemStatus::Succeeded, 'imported');
                        } catch (Throwable $exception) {
                            $this->runs->finishItem($runItem, SyncRunItemStatus::Failed,
                                'invalid_item', 'invalid_item', $exception->getMessage());
                        }
                    }
                    $next = $page['next_cursor'];
                    if ($next !== null && (! is_string($next) || $next === ''
                        || strlen($next) > 1024 || $next === $cursor
                        || isset($seenCursors[$next]))) {
                        throw new RuntimeException('WordPress subscriber snapshot returned a repeated or invalid cursor.');
                    }
                    if ($next !== null) {
                        $seenCursors[$next] = true;
                    }
                    $cursor = $next;
                } while ($cursor !== null);
            }
        } catch (Throwable $exception) {
            $runItem = $this->runs->addItem($run, [
                'operation' => 'wordpress.subscriber_snapshot', 'provider_reference' => null,
            ]);
            $this->runs->markProcessing($runItem);
            $this->runs->finishItem($runItem, SyncRunItemStatus::Failed,
                'provider_error', 'provider_error', $exception->getMessage());
        }
        return $this->runs->complete($run, $actor);
    }

    private function importItem(array $item, string $sourceSite, ?string $source): void
    {
        $id = $item['external_subscription_id'] ?? null;
        $externalId = is_string($id) ? trim($id) : '';
        $value = $item['email'] ?? null;
        $email = is_string($value) ? strtolower(trim($value)) : '';
        $status = $item['status'] ?? null;
        if ($externalId === '' || strlen($externalId) > 255 || $email === ''
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Subscriber requires a valid email and external_subscription_id.');
        }
        if (! is_string($status)
            || ! in_array($status, ['subscribed','unsubscribed','suppressed','pending','unknown'], true)) {
            throw new \InvalidArgumentException('Subscriber has an unsupported status.');
        }
        if (isset($item['source_site']) && $item['source_site'] !== $sourceSite) {
            throw new \InvalidArgumentException('Subscriber item source_site differs from the signed snapshot.');
        }
        if (isset($item['provider']) && $item['provider'] !== 'wordpress') {
            throw new \InvalidArgumentException('Subscriber provider does not match WordPress.');
        }

        if ($source === self::NativeSource) {
            if (! preg_match('/^andy_core_newsletter:[a-f0-9]{64}$/', $externalId)
                || $status === 'unknown') {
                throw new \InvalidArgumentException('Native Newsletter ID or status is not valid.');
            }
            if (! is_string($item['status_updated_at'] ?? null)) {
                throw new \InvalidArgumentException('Native Newsletter requires status_updated_at.');
            }
        } elseif (str_starts_with($externalId, 'andy_core_newsletter:')) {
            throw new \InvalidArgumentException('Native Newsletter ID cannot arrive from the Elementor legacy stream.');
        }

        foreach (['subscribed_at', 'unsubscribed_at', 'status_updated_at'] as $field) {
            if (array_key_exists($field, $item) && $item[$field] !== null) {
                $this->timestamp($item[$field], $field);
            }
        }
        if ($status === 'subscribed' && $source === self::NativeSource
            && empty($item['subscribed_at'])) {
            throw new \InvalidArgumentException('Confirmed Newsletter consent requires subscribed_at.');
        }
        if ($status === 'unsubscribed' && $source === self::NativeSource
            && empty($item['unsubscribed_at'])) {
            throw new \InvalidArgumentException('Newsletter unsubscribe requires unsubscribed_at.');
        }
        $this->writer->import([...$item, 'external_subscription_id' => $externalId,
            'email' => $email, 'status' => $status], $sourceSite);
    }

    private function validSite(string $site): bool
    {
        return $site !== '' && strlen($site) <= 255
            && preg_match('/^(?=.{1,253}$)[a-z0-9]+(?:[a-z0-9.-]*[a-z0-9])?$/i', $site) === 1
            && ! str_contains($site, '..') && ! str_contains($site, '/');
    }

    private function timestamp(mixed $raw, string $field): CarbonImmutable
    {
        if (! is_string($raw) || ! preg_match(
            '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:Z|[+-]\\d{2}:\\d{2})$/', $raw
        )) {
            throw new \InvalidArgumentException('Invalid '.$field.' timestamp.');
        }
        try {
            $time = CarbonImmutable::parse($raw);
            if ($time->isFuture()) {
                throw new \InvalidArgumentException('Future '.$field.' timestamp.');
            }
            return $time;
        } catch (Throwable $exception) {
            throw new \InvalidArgumentException('Invalid '.$field.' timestamp.', 0, $exception);
        }
    }
}
