<?php

namespace App\Modules\Sync\Infrastructure\WooCommerce;

use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;
use App\Modules\Sync\Application\WooCommerceTrackingConflictException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class HttpWooCommerceTrackingGateway implements WooCommerceTrackingGateway
{
    private const string UserAgent = 'Mozilla/5.0 (compatible; BYL-ERP-WooCommerce-Inbound/1.0; +https://www.beyourlover.com/)';

    public function descriptor(): array
    {
        return [
            'configured' => $this->configured(),
            'url' => $this->baseUrl(),
            'connection_key' => $this->connectionKey(),
        ];
    }

    public function inspectTracking(int $providerOrderId): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('WooCommerce connection is not configured.');
        }

        $state = $this->trackingState($this->readOrder($providerOrderId));

        return [
            'tracking_number' => $state['number'],
            'provider' => $state['provider'],
            'ship_date' => $state['ship_date'],
            'has_tracking' => $this->hasTracking($state),
        ];
    }

    public function syncTracking(
        int $providerOrderId,
        string $providerSlug,
        string $trackingNumber,
        string $shipDate,
    ): array {
        if (! $this->configured()) {
            throw new RuntimeException('WooCommerce connection is not configured.');
        }

        $before = $this->readOrder($providerOrderId);
        $state = $this->trackingState($before);
        if ($this->matches($state, $providerSlug, $trackingNumber)) {
            return ['written' => false, 'verified' => true];
        }
        if ($this->hasTracking($state)) {
            throw new WooCommerceTrackingConflictException('WooCommerce order already contains different tracking data.');
        }

        $trackingItem = $this->trackingItem($providerSlug, $trackingNumber, $shipDate);
        $this->writeTrackingMeta($providerOrderId, $before, $trackingItem);

        $after = $this->trackingState($this->readOrder($providerOrderId));
        if (! $this->matches($after, $providerSlug, $trackingNumber)) {
            throw new RuntimeException('WooCommerce tracking verification failed.');
        }

        return ['written' => true, 'verified' => true];
    }

    /** @return array<string,mixed> */
    private function readOrder(int $providerOrderId): array
    {
        $payload = $this->client()
            ->get($this->orderUrl($providerOrderId))
            ->throw()
            ->json();

        if (! is_array($payload)) {
            throw new RuntimeException('WooCommerce order response is invalid.');
        }

        return $payload;
    }

    /** @return array{items:array<int,mixed>,number:string,provider:string,ship_date:?string} */
    private function trackingState(array $order): array
    {
        $values = [];
        foreach (($order['meta_data'] ?? []) as $meta) {
            if (! is_array($meta) || ! isset($meta['key'])) {
                continue;
            }
            $values[(string) $meta['key']] = $meta['value'] ?? null;
        }

        $items = is_array($values['_aftership_tracking_items'] ?? null)
            ? array_values($values['_aftership_tracking_items'])
            : [];
        $number = trim((string) ($values['_aftership_tracking_number'] ?? ''));
        $provider = trim((string) ($values['_aftership_tracking_provider_name'] ?? ''));
        $shipDate = null;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (trim((string) ($item['tracking_number'] ?? '')) !== $number
                || trim((string) ($item['slug'] ?? '')) !== $provider) {
                continue;
            }
            $candidate = trim((string) (($item['additional_fields']['ship_date'] ?? '')));
            $shipDate = $candidate !== '' ? $candidate : null;
            break;
        }

        return [
            'items' => $items,
            'number' => $number,
            'provider' => $provider,
            'ship_date' => $shipDate,
        ];
    }

    private function hasTracking(array $state): bool
    {
        return $state['items'] !== [] || $state['number'] !== '' || $state['provider'] !== '';
    }

    private function matches(array $state, string $providerSlug, string $trackingNumber): bool
    {
        if ($state['number'] !== $trackingNumber || $state['provider'] !== $providerSlug) {
            return false;
        }

        foreach ($state['items'] as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (trim((string) ($item['slug'] ?? '')) === $providerSlug
                && trim((string) ($item['tracking_number'] ?? '')) === $trackingNumber) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    private function trackingItem(string $providerSlug, string $trackingNumber, string $shipDate): array
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z');

        return [
            'slug' => $providerSlug,
            'tracking_number' => $trackingNumber,
            'tracking_id' => md5($providerSlug.'-'.$trackingNumber),
            'additional_fields' => [
                'account_number' => '',
                'key' => '',
                'postal_code' => '',
                'ship_date' => $shipDate,
                'destination_country' => '',
                'state' => '',
            ],
            'metrics' => [
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];
    }

    private function writeTrackingMeta(int $providerOrderId, array $order, array $trackingItem): void
    {
        $meta = [
            '_aftership_tracking_items' => [$trackingItem],
            '_aftership_tracking_number' => $trackingItem['tracking_number'],
            '_aftership_tracking_provider_name' => $trackingItem['slug'],
        ];
        $payload = ['meta_data' => []];

        foreach ($meta as $key => $value) {
            $entry = ['key' => $key, 'value' => $value];
            foreach (($order['meta_data'] ?? []) as $existing) {
                if (is_array($existing) && ($existing['key'] ?? null) === $key && (int) ($existing['id'] ?? 0) > 0) {
                    $entry['id'] = (int) $existing['id'];
                    break;
                }
            }
            $payload['meta_data'][] = $entry;
        }

        $this->client()
            ->put($this->orderUrl($providerOrderId), $payload)
            ->throw();
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()
            ->withHeaders(['User-Agent' => self::UserAgent])
            ->withBasicAuth(
                (string) config('services.woocommerce.consumer_key'),
                (string) config('services.woocommerce.consumer_secret'),
            )
            ->timeout(max(1, (int) config('services.woocommerce.timeout', 15)));
    }

    private function configured(): bool
    {
        return $this->baseUrl() !== ''
            && trim((string) config('services.woocommerce.consumer_key')) !== ''
            && trim((string) config('services.woocommerce.consumer_secret')) !== '';
    }

    private function orderUrl(int $providerOrderId): string
    {
        return $this->baseUrl().'/wp-json/wc/v3/orders/'.$providerOrderId;
    }

    private function baseUrl(): string
    {
        return rtrim(trim((string) config('services.woocommerce.url')), '/');
    }

    private function connectionKey(): string
    {
        return trim((string) config('services.woocommerce.connection_key', 'beyourlover.com')) ?: 'beyourlover.com';
    }
}
