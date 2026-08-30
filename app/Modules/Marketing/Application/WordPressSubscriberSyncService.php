<?php

namespace App\Modules\Marketing\Application;

use App\Modules\Marketing\Application\Contracts\WordPressSubscriberGateway;
use App\Modules\Marketing\Application\Contracts\MarketingSubscriberSyncWriter;
use App\Modules\Sync\Application\SyncRunItemStatus;
use App\Modules\Sync\Application\SyncRunService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

final class WordPressSubscriberSyncService
{
    private const int PageLimit = 100;
    private const int MaximumPages = 100;

    public function __construct(private readonly WordPressSubscriberGateway $gateway, private readonly MarketingSubscriberSyncWriter $writer, private readonly SyncRunService $runs) {}

    public function sync(?Authenticatable $actor): Model
    {
        $descriptor = $this->gateway->descriptor();
        $connection = $descriptor['connection_key'] ?: null;
        $run = $this->runs->start('wordpress_subscribers_snapshot', 0, $actor, 'wordpress', $connection, ['domain' => 'marketing', 'resource' => 'subscribers']);
        $cursor = null; $seenCursors = []; $pages = 0;
        try {
            do {
                if (++$pages > self::MaximumPages) throw new \RuntimeException('WordPress subscriber snapshot exceeded the page safety limit.');
                $page = $this->gateway->snapshotSubscribers($cursor, null, self::PageLimit);
                $sourceSite = $page['source_site'] ?: $connection;
                foreach ($page['items'] as $item) {
                    $providerReference = is_array($item) && is_scalar($item['external_subscription_id'] ?? null) ? (string) $item['external_subscription_id'] : null;
                    $runItem = $this->runs->addItem($run, ['operation' => 'wordpress.subscriber_import', 'provider_reference' => $providerReference]);
                    $this->runs->markProcessing($runItem);
                    try { if (! is_array($item)) throw new \InvalidArgumentException('Subscriber item must be an object.'); $this->importItem($item, $sourceSite); $this->runs->finishItem($runItem, SyncRunItemStatus::Succeeded, 'imported'); }
                    catch (Throwable $exception) { $this->runs->finishItem($runItem, SyncRunItemStatus::Failed, 'invalid_item', 'invalid_item', $exception->getMessage()); }
                }
                $next = $page['next_cursor'];
                if ($next !== null && in_array($next, $seenCursors, true)) throw new \RuntimeException('WordPress subscriber snapshot returned a repeated cursor.');
                if ($next !== null) $seenCursors[] = $next;
                $cursor = $next;
            } while ($cursor !== null);
        } catch (Throwable $exception) {
            $runItem = $this->runs->addItem($run, ['operation' => 'wordpress.subscriber_snapshot', 'provider_reference' => $cursor]);
            $this->runs->markProcessing($runItem);
            $this->runs->finishItem($runItem, SyncRunItemStatus::Failed, 'provider_error', 'provider_error', $exception->getMessage());
        }
        return $this->runs->complete($run, $actor);
    }

    private function importItem(array $item, ?string $sourceSite): void
    {
        $externalId = trim((string) ($item['external_subscription_id'] ?? ''));
        $email = strtolower(trim((string) ($item['email'] ?? '')));
        $status = (string) ($item['status'] ?? '');
        if ($externalId === '' || $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new \InvalidArgumentException('Subscriber item requires a valid email and external_subscription_id.');
        if (! in_array($status, ['subscribed', 'unsubscribed', 'suppressed', 'pending', 'unknown'], true)) throw new \InvalidArgumentException('Subscriber item has an unsupported status.');
        $this->writer->import([...$item, 'external_subscription_id' => $externalId, 'email' => $email, 'status' => $status], $sourceSite);
    }
}
