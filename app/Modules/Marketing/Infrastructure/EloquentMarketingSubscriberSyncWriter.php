<?php

namespace App\Modules\Marketing\Infrastructure;

use App\Modules\Marketing\Application\Contracts\MarketingSubscriberSyncWriter;
use App\Modules\Marketing\Infrastructure\Models\MarketingSubscriber;
use App\Modules\Marketing\Infrastructure\Models\MarketingSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

final class EloquentMarketingSubscriberSyncWriter implements MarketingSubscriberSyncWriter
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function import(array $item, ?string $sourceSite): void
    {
        $this->database->transaction(function () use ($item, $sourceSite): void {
            $subscriber = MarketingSubscriber::query()->firstOrNew(['email' => strtolower(trim((string) $item['email']))]);
            $firstName = $this->nullableString($item['first_name'] ?? null);
            $lastName = $this->nullableString($item['last_name'] ?? null);
            if ($firstName !== null) {
                $subscriber->first_name = $firstName;
            }
            if ($lastName !== null) {
                $subscriber->last_name = $lastName;
            }
            $subscriber->save();
            $subscription = MarketingSubscription::query()->firstOrNew(['provider' => 'wordpress', 'external_subscription_id' => trim((string) $item['external_subscription_id'])]);
            $subscription->fill(['subscriber_id' => $subscriber->id, 'channel' => 'email', 'source_site' => $sourceSite, 'external_list_id' => $this->nullableString($item['external_list_id'] ?? null), 'external_list_name' => $this->nullableString($item['external_list_name'] ?? null), 'status' => $item['status'], 'consent_source' => $this->nullableString($item['consent_source'] ?? null), 'subscribed_at' => $this->nullableString($item['subscribed_at'] ?? null), 'unsubscribed_at' => $this->nullableString($item['unsubscribed_at'] ?? null), 'status_updated_at' => $this->nullableString($item['status_updated_at'] ?? null), 'last_synced_at' => CarbonImmutable::now()]);
            $subscription->save();
        });
    }

    private function nullableString(mixed $value): ?string { if ($value === null || ! is_scalar($value)) return null; $value = trim((string) $value); return $value === '' ? null : $value; }
}
