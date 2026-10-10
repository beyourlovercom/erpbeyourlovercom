<?php

namespace App\Modules\Marketing\Infrastructure;

use App\Modules\Marketing\Application\Contracts\MarketingSubscriberSyncWriter;
use App\Modules\Marketing\Infrastructure\Models\MarketingSubscriber;
use App\Modules\Marketing\Infrastructure\Models\MarketingSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

final class EloquentMarketingSubscriberSyncWriter implements MarketingSubscriberSyncWriter
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function import(array $item, ?string $sourceSite): void
    {
        $this->database->transaction(function () use ($item, $sourceSite): void {
            $email = strtolower(trim((string) $item['email']));
            $externalId = trim((string) $item['external_subscription_id']);
            $status = (string) $item['status'];
            // Lock the existing provenance row, then compare time and state BEFORE
            // touching subscriber attributes: a stale snapshot is a total no-op.
            $existing = MarketingSubscription::query()
                ->where('provider', 'wordpress')
                ->where('external_subscription_id', $externalId)
                ->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->subscriber?->email !== $email
                    || ($existing->source_site !== null && $existing->source_site !== $sourceSite)) {
                    throw new InvalidArgumentException('WordPress subscription identity or source_site changed.');
                }
                if (! $this->shouldApply($existing, $item)) {
                    return;
                }
            }

            $subscriber = MarketingSubscriber::query()->firstOrNew(['email' => $email]);
            $firstName = $this->nullableString($item['first_name'] ?? null);
            $lastName = $this->nullableString($item['last_name'] ?? null);
            if ($firstName !== null) $subscriber->first_name = $firstName;
            if ($lastName !== null) $subscriber->last_name = $lastName;
            $subscriber->save();

            $subscription = $existing ?? new MarketingSubscription([
                'provider' => 'wordpress', 'external_subscription_id' => $externalId,
            ]);
            $incomingTime = $this->nullableString($item['status_updated_at'] ?? null);
            // Older Elementor connectors may omit status_updated_at when reporting
            // unsubscribe/suppression; record the local receive time for fail-closed
            // monotonic state, never fabricate a positive marketing opt-in.
            if ($incomingTime === null && in_array($status, ['unsubscribed', 'suppressed'], true)) {
                $incomingTime = CarbonImmutable::now('UTC')->toIso8601String();
            }
            $subscription->fill([
                'subscriber_id' => $subscriber->id,
                'channel' => 'email',
                'source_site' => $sourceSite,
                'external_list_id' => $this->nullableString($item['external_list_id'] ?? null),
                'external_list_name' => $this->nullableString($item['external_list_name'] ?? null),
                'status' => $status,
                'consent_source' => $this->nullableString($item['consent_source'] ?? null),
                'subscribed_at' => $this->nullableString($item['subscribed_at'] ?? null),
                'unsubscribed_at' => $this->nullableString($item['unsubscribed_at'] ?? null),
                'status_updated_at' => $incomingTime,
                'last_synced_at' => CarbonImmutable::now('UTC'),
            ]);
            $subscription->save();
        });
    }

    private function shouldApply(MarketingSubscription $existing, array $item): bool
    {
        $current = (string) $existing->status;
        $next = (string) $item['status'];
        if ($current === 'suppressed' && $next !== 'suppressed') {
            throw new InvalidArgumentException('Suppressed subscriber cannot be automatically reactivated.');
        }
        if ($current === 'unsubscribed' && $next === 'subscribed') {
            throw new InvalidArgumentException('A new pending re-consent is required before subscribing again.');
        }
        $incomingRaw = $this->nullableString($item['status_updated_at'] ?? null);
        $incoming = $incomingRaw === null ? null : CarbonImmutable::parse($incomingRaw);
        $currentTime = $existing->status_updated_at === null
            ? null : CarbonImmutable::parse($existing->status_updated_at);
        if ($incoming === null) {
            if ($next === $current) {
                return false; // repeat of a legacy undated record is idempotent
            }
            if (! in_array($next, ['unsubscribed', 'suppressed'], true)) {
                throw new InvalidArgumentException('Undated snapshot cannot grant or reinstate consent.');
            }
            return true; // conservative legacy opt-out, stamped at ingest
        }
        if ($currentTime !== null && $incoming->lessThan($currentTime)) {
            throw new InvalidArgumentException('Stale WordPress subscriber snapshot rejected.');
        }
        if ($currentTime !== null && $incoming->equalTo($currentTime)) {
            if ($next === $current) return false;
            // Equal timestamps are ambiguous; keep the more protective state.
            if ($this->priority($next) <= $this->priority($current)) {
                throw new InvalidArgumentException('Equal-time snapshot cannot reduce unsubscribe protection.');
            }
        }
        if ($current === 'unsubscribed' && $next === 'pending') {
            if ($incomingRaw === null || $currentTime === null
                || ! $incoming->greaterThan($currentTime)
                || empty($item['consent_source'])) {
                throw new InvalidArgumentException('New consent requires later pending evidence.');
            }
        }
        return true;
    }

    private function priority(string $status): int
    {
        return match ($status) {
            'suppressed' => 5,
            'unsubscribed' => 4,
            'pending' => 3,
            'unknown' => 2,
            'subscribed' => 1,
            default => 0,
        };
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value)) return null;
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
