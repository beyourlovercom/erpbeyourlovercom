<?php

namespace App\Modules\Sync\Infrastructure\WooCommerce;

use App\Modules\Sync\Application\Contracts\WooCommerceTrackingBatchInspector;
use App\Modules\Sync\Application\Contracts\WooCommerceTrackingGateway;
use App\Modules\Sync\Application\WooCommerceOrderStatusUnsupportedException;
use App\Modules\Sync\Application\WooCommerceTrackingConflictException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class HttpWooCommerceTrackingGateway implements WooCommerceTrackingBatchInspector, WooCommerceTrackingGateway
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

    public function inspectOrderStatus(int $providerOrderId): string
    {
        return strtolower(trim((string) ($this->readOrder($providerOrderId)['status'] ?? '')));
    }

    public function inspectTracking(int $providerOrderId): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('WooCommerce connection is not configured.');
        }

        $state = $this->trackingState($this->readOrder($providerOrderId));
        $first = collect($state['items'])->first(fn ($item): bool => is_array($item));
        $number = trim((string) ($first['tracking_number'] ?? $state['legacy_number']));
        $provider = trim((string) ($first['slug'] ?? $state['legacy_provider']));
        $shipDate = trim((string) data_get($first, 'additional_fields.ship_date', '')) ?: null;

        return [
            'tracking_number' => $number,
            'provider' => $provider,
            'ship_date' => $shipDate,
            'has_tracking' => $state['items'] !== [] || $number !== '' || $provider !== '',
        ];
    }

    public function inspectTrackingBatch(array $providerOrderIds): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('WooCommerce connection is not configured.');
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $providerOrderIds),
            fn (int $id): bool => $id > 0,
        )));
        if ($ids === []) {
            return [];
        }

        $result = [];
        // The full Sync Grid refresh inspects ~2k orders. Bounded triple
        // read-only concurrency avoids ~20 serial Woo roundtrips while never
        // writing an order or launching an unbounded request pool.
        $chunks = array_chunk($ids, 100);
        foreach (array_chunk($chunks, 3) as $group) {
            $payloads = [];
            if (count($group) === 1) {
                $chunk = $group[0];
                $payloads[] = $this->client()->get($this->baseUrl().'/wp-json/wc/v3/orders', [
                    'include' => $chunk,
                    'per_page' => count($chunk),
                ])->throw()->json();
            } else {
                $responses = Http::pool(function (Pool $pool) use ($group): void {
                    foreach ($group as $index => $chunk) {
                        $pool->as((string) $index)
                            ->acceptJson()
                            ->withHeaders(['User-Agent' => self::UserAgent])
                            ->withBasicAuth(
                                (string) config('services.woocommerce.consumer_key'),
                                (string) config('services.woocommerce.consumer_secret'),
                            )
                            ->timeout(max(1, (int) config('services.woocommerce.timeout', 15)))
                            ->get($this->baseUrl().'/wp-json/wc/v3/orders', [
                                'include' => $chunk,
                                'per_page' => count($chunk),
                            ]);
                    }
                });

                foreach ($group as $index => $chunk) {
                    $response = $responses[(string) $index] ?? null;
                    if (! $response instanceof Response) {
                        throw new RuntimeException('WooCommerce order batch read did not return a valid response.');
                    }
                    $payloads[] = $response->throw()->json();
                }
            }

            foreach ($payloads as $payload) {
                if (! is_array($payload)) {
                    throw new RuntimeException('WooCommerce order batch response is invalid.');
                }

            foreach ($payload as $order) {
                if (! is_array($order) || (int) ($order['id'] ?? 0) <= 0) {
                    continue;
                }

                $providerOrderId = (int) $order['id'];
                $state = $this->trackingState($order);
                $items = [];
                foreach ($state['items'] as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $number = trim((string) ($item['tracking_number'] ?? ''));
                    if ($number === '') {
                        continue;
                    }
                    $items[] = [
                        'tracking_number' => $number,
                        'provider' => trim((string) ($item['slug'] ?? '')),
                        'ship_date' => trim((string) data_get($item, 'additional_fields.ship_date', '')) ?: null,
                    ];
                }
                if ($items === [] && $state['legacy_number'] !== '') {
                    $items[] = [
                        'tracking_number' => $state['legacy_number'],
                        'provider' => $state['legacy_provider'],
                        'ship_date' => null,
                    ];
                }

                $result[$providerOrderId] = [
                    'order_status' => strtolower(trim((string) ($order['status'] ?? ''))),
                    'tracking_items' => $items,
                    'has_tracking' => $items !== [],
                    'checked_at' => now()->toIso8601String(),
                ];
                }
            }
        }

        foreach ($ids as $id) {
            $result[$id] ??= [
                'order_status' => 'not_found',
                'tracking_items' => [],
                'has_tracking' => false,
                'checked_at' => now()->toIso8601String(),
            ];
        }

        return $result;
    }

    public function syncTracking(
        int $providerOrderId,
        string $providerSlug,
        string $trackingNumber,
        string $shipDate,
        array $lineItems = [],
    ): array {
        if (! $this->configured()) {
            throw new RuntimeException('WooCommerce connection is not configured.');
        }

        $before = $this->readOrder($providerOrderId);
        $currentStatus = strtolower(trim((string) ($before['status'] ?? '')));
        if (in_array($currentStatus, ['cancelled', 'refunded', 'failed', 'on-hold', 'trash'], true)) {
            throw new WooCommerceTrackingConflictException(
                'WooCommerce order has a protected status; Tracking changes are blocked.'
            );
        }
        $state = $this->trackingState($before);
        $resolvedLineItems = $this->resolveRemoteLineItems($before, $lineItems);
        if ($lineItems === []) {
            // An existing package with this exact carrier and Tracking must
            // retain its original line-item bindings on a repeat manual push.
            foreach ($state['items'] as $existing) {
                if (is_array($existing) && (string) ($existing['tracking_number'] ?? '') === $trackingNumber
                    && (string) ($existing['slug'] ?? '') === $providerSlug) {
                    $resolvedLineItems = $this->lineBindings($existing);
                    break;
                }
            }
        }
        $trackingItem = $this->trackingItem($providerSlug, $trackingNumber, $shipDate, $resolvedLineItems);
        $trackingId = (string) $trackingItem['tracking_id'];
        $items = $state['items'];

        if ($items === [] && ($state['legacy_number'] !== '' || $state['legacy_provider'] !== '')) {
            if ($state['legacy_number'] !== $trackingNumber || $state['legacy_provider'] !== $providerSlug) {
                throw new WooCommerceTrackingConflictException('WooCommerce order contains legacy tracking data that does not match this package.');
            }
        }

        $matchingIndexes = [];
        $requestedIds = array_column($this->lineBindings($trackingItem), 'id');
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                throw new WooCommerceTrackingConflictException('WooCommerce tracking items contain an invalid record.');
            }
            $itemId = trim((string) ($item['tracking_id'] ?? ''));
            $itemSlug = trim((string) ($item['slug'] ?? ''));
            $itemNumber = trim((string) ($item['tracking_number'] ?? ''));
            if ($itemNumber === $trackingNumber && $itemSlug !== $providerSlug) {
                throw new WooCommerceTrackingConflictException('WooCommerce order contains the same tracking number under a different carrier.');
            }
            if ($itemId === $trackingId) {
                $matchingIndexes[] = $index;

                continue;
            }

            $claimed = array_column($this->lineBindings($item), 'id');
            if (array_intersect($requestedIds, $claimed) !== []) {
                throw new WooCommerceTrackingConflictException('WooCommerce line item is already assigned to another tracking package.');
            }
        }
        if (count($matchingIndexes) > 1) {
            throw new WooCommerceTrackingConflictException('WooCommerce order contains duplicate records for the same tracking package.');
        }

        $written = true;
        if ($matchingIndexes !== []) {
            $index = $matchingIndexes[0];
            $existing = $items[$index];
            if ($this->samePackage($existing, $trackingItem)) {
                $written = false;
            } else {
                if (isset($existing['metrics']['created_at'])) {
                    $trackingItem['metrics']['created_at'] = $existing['metrics']['created_at'];
                }
                $items[$index] = $trackingItem;
            }
        } else {
            $items[] = $trackingItem;
        }

        if (! $written) {
            return ['written' => false, 'verified' => true, 'package_count' => count($items)];
        }

        $beforeIds = collect($state['items'])
            ->filter(fn ($item): bool => is_array($item) && filled($item['tracking_id'] ?? null))
            ->pluck('tracking_id')->map(fn ($id): string => (string) $id)->all();
        $this->writeTrackingMeta($providerOrderId, $before, array_values($items));

        $afterState = $this->trackingState($this->readOrder($providerOrderId));
        $afterItem = collect($afterState['items'])->first(fn ($item): bool => is_array($item)
            && (string) ($item['tracking_id'] ?? '') === $trackingId);
        if (! is_array($afterItem) || ! $this->samePackage($afterItem, $trackingItem)) {
            throw new RuntimeException('WooCommerce tracking package verification failed.');
        }
        $afterIds = collect($afterState['items'])
            ->filter(fn ($item): bool => is_array($item) && filled($item['tracking_id'] ?? null))
            ->pluck('tracking_id')->map(fn ($id): string => (string) $id)->all();
        if (array_diff($beforeIds, $afterIds) !== []) {
            throw new RuntimeException('WooCommerce tracking verification detected a lost pre-existing package.');
        }

        return ['written' => true, 'verified' => true, 'package_count' => count($afterState['items'])];
    }

    /**
     * Explicit order-detail Tracking correction. Never modifies Woo order status,
     * line items, address, SKU, notes or customer notifications.
     * Multi-parcel replacement is deliberately rejected when the target is ambiguous.
     *
     * @return array{written:bool,verified:bool}
     */
    public function forceSyncSingleTracking(int $providerOrderId, string $slug, string $number, string $shipDate): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('WooCommerce connection is not configured.');
        }
        if (trim($number) === '') {
            throw new RuntimeException('Missing ERP tracking number.');
        }

        $before = $this->readOrder($providerOrderId);
        $state = $this->trackingState($before);
        $items = $state['items'];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new WooCommerceTrackingConflictException('Invalid WooCommerce tracking record.');
            }
            if (trim((string) ($item['tracking_number'] ?? '')) === $number) {
                return ['written' => false, 'verified' => true];
            }
        }
        if (count($items) > 1) {
            throw new WooCommerceTrackingConflictException('官网有多个 A/B/C 包裹，无法确定应替换哪一个 Tracking；请先人工定位包裹。');
        }

        if (count($items) === 1) {
            $old = $items[0];
            // Preserve the old parcel's line bindings and date; only correct carrier/number.
            $shipDate = trim((string) data_get($old, 'additional_fields.ship_date', '')) ?: $shipDate;
            $replacement = $this->trackingItem($slug, $number, $shipDate, $this->lineBindings($old));
            $replacement['additional_fields'] = array_replace(
                $replacement['additional_fields'],
                (array) ($old['additional_fields'] ?? [])
            );
            if (isset($old['metrics']['created_at'])) {
                $replacement['metrics']['created_at'] = $old['metrics']['created_at'];
            }
            $items = [$replacement];
        } else {
            $legacy = $state['legacy_number'];
            if ($legacy === $number) {
                return ['written' => false, 'verified' => true];
            }
            $items = [$this->trackingItem($slug, $number, $shipDate, [])];
        }

        $this->writeTrackingMeta($providerOrderId, $before, $items);
        $after = $this->trackingState($this->readOrder($providerOrderId));
        if (count($after['items']) !== 1
            || (string) ($after['items'][0]['tracking_number'] ?? '') !== $number
            || (string) ($after['items'][0]['slug'] ?? '') !== $slug) {
            throw new RuntimeException('WooCommerce Tracking 写入后回读不一致。');
        }
        return ['written' => true, 'verified' => true];
    }

    public function syncOrderStatus(int $providerOrderId, string $status): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('WooCommerce connection is not configured.');
        }
        $status = strtolower(trim($status));
        if ($status === '' || preg_match('/^[a-z0-9-]{1,32}$/', $status) !== 1) {
            throw new RuntimeException('WooCommerce status is invalid.');
        }

        $before = $this->readOrder($providerOrderId);
        $current = strtolower(trim((string) ($before['status'] ?? '')));
        if ($current === $status) {
            return ['written' => false, 'verified' => true, 'status' => $status];
        }
        // P0: an old Woo Completed order must not be silently reverted by
        // a later ERP drift/partial-shipment sync. Historical fixes require
        // individually verified, explicitly controlled action.
        if ($status === 'processing' && in_array($current, ['completed', 'completed-delivered'], true)) {
            throw new WooCommerceOrderStatusUnsupportedException(
                'Existing Woo Completed order may not be automatically reverted to Processing.'
            );
        }
        if (in_array($current, ['cancelled', 'refunded', 'failed', 'on-hold', 'trash'], true)) {
            throw new WooCommerceOrderStatusUnsupportedException(
                'WooCommerce has a protected status; manual status reconciliation was blocked.'
            );
        }

        $this->assertOrderStatusSupported($status);
        $this->client()->put($this->orderUrl($providerOrderId), ['status' => $status])->throw();
        $after = $this->readOrder($providerOrderId);
        if (strtolower(trim((string) ($after['status'] ?? ''))) !== $status) {
            throw new RuntimeException('WooCommerce order status verification failed.');
        }

        return ['written' => true, 'verified' => true, 'status' => $status];
    }

    private function assertOrderStatusSupported(string $status): void
    {
        if (in_array($status, ['processing', 'completed'], true)) {
            return;
        }

        $payload = $this->client()->get($this->baseUrl().'/wp-json/wc/v3/orders/statuses')->throw()->json();
        if (! is_array($payload)) {
            throw new WooCommerceOrderStatusUnsupportedException('WooCommerce order status registry response is invalid.');
        }

        $supported = collect($payload)->contains(fn ($item): bool => is_array($item)
            && strtolower(trim((string) ($item['slug'] ?? ''))) === $status);
        if (! $supported) {
            throw new WooCommerceOrderStatusUnsupportedException("WooCommerce order status {$status} is not registered on the target site.");
        }
    }

    /** @return array<string,mixed> */
    private function readOrder(int $providerOrderId): array
    {
        $payload = $this->client()->get($this->orderUrl($providerOrderId))->throw()->json();
        if (! is_array($payload)) {
            throw new RuntimeException('WooCommerce order response is invalid.');
        }

        return $payload;
    }

    /** @return array{items:array<int,mixed>,legacy_number:string,legacy_provider:string} */
    private function trackingState(array $order): array
    {
        $values = [];
        foreach (($order['meta_data'] ?? []) as $meta) {
            if (is_array($meta) && isset($meta['key'])) {
                $values[(string) $meta['key']] = $meta['value'] ?? null;
            }
        }

        return [
            'items' => is_array($values['_aftership_tracking_items'] ?? null)
                ? array_values($values['_aftership_tracking_items']) : [],
            'legacy_number' => trim((string) ($values['_aftership_tracking_number'] ?? '')),
            'legacy_provider' => trim((string) ($values['_aftership_tracking_provider_name'] ?? '')),
        ];
    }

    /** @param array<int,array{id:int,quantity:int}> $bindings */
    private function resolveRemoteLineItems(array $order, array $bindings): array
    {
        // The explicit *manual* button may send an order-level AfterShip package
        // with no guessed SKU/line binding. Automatic callers still supply
        // validated explicit bindings from WooCommerceTrackingOutboundService.
        if ($bindings === []) {
            return [];
        }
        $remote = collect(is_array($order['line_items'] ?? null) ? $order['line_items'] : [])
            ->filter(fn ($item): bool => is_array($item) && (int) ($item['id'] ?? 0) > 0)
            ->keyBy(fn ($item): int => (int) $item['id']);
        $seen = [];
        $resolved = [];
        foreach ($bindings as $binding) {
            $id = (int) ($binding['id'] ?? 0);
            $quantity = (int) ($binding['quantity'] ?? 0);
            if ($id <= 0 || $quantity <= 0 || isset($seen[$id])) {
                throw new WooCommerceTrackingConflictException('WooCommerce line-item binding is invalid or duplicated.');
            }
            $seen[$id] = true;
            $item = $remote->get($id);
            if (! is_array($item)) {
                throw new WooCommerceTrackingConflictException("WooCommerce line item {$id} is not present on the root order.");
            }
            $remoteQuantity = (int) ($item['quantity'] ?? 0);
            if ($remoteQuantity < $quantity) {
                throw new WooCommerceTrackingConflictException("WooCommerce line item {$id} quantity is lower than the ERP package quantity.");
            }
            $resolved[] = ['id' => $id, 'quantity' => $quantity];
        }

        return $resolved;
    }

    private function samePackage(array $left, array $right): bool
    {
        return trim((string) ($left['tracking_id'] ?? '')) === trim((string) ($right['tracking_id'] ?? ''))
            && trim((string) ($left['slug'] ?? '')) === trim((string) ($right['slug'] ?? ''))
            && trim((string) ($left['tracking_number'] ?? '')) === trim((string) ($right['tracking_number'] ?? ''))
            && trim((string) data_get($left, 'additional_fields.ship_date', '')) === trim((string) data_get($right, 'additional_fields.ship_date', ''))
            && $this->lineBindings($left) === $this->lineBindings($right);
    }

    /** @return array<int,array{id:int,quantity:int}> */
    private function lineBindings(array $trackingItem): array
    {
        $bindings = [];
        foreach (($trackingItem['line_items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = (int) ($item['id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);
            if ($id > 0 && $quantity > 0) {
                $bindings[] = ['id' => $id, 'quantity' => $quantity];
            }
        }
        usort($bindings, fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $bindings;
    }

    private function trackingItem(string $providerSlug, string $trackingNumber, string $shipDate, array $lineItems): array
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z');

        return [
            'slug' => $providerSlug,
            'tracking_number' => $trackingNumber,
            'tracking_id' => md5($providerSlug.'-'.$trackingNumber),
            'additional_fields' => [
                'account_number' => '', 'key' => '', 'postal_code' => '', 'ship_date' => $shipDate,
                'destination_country' => '', 'state' => '',
            ],
            'line_items' => $lineItems,
            'metrics' => ['created_at' => $now, 'updated_at' => $now],
        ];
    }

    private function writeTrackingMeta(int $providerOrderId, array $order, array $trackingItems): void
    {
        if ($trackingItems === []) {
            throw new RuntimeException('WooCommerce tracking package list cannot be empty.');
        }
        $first = $trackingItems[0];
        $meta = [
            '_aftership_tracking_items' => array_values($trackingItems),
            '_aftership_tracking_number' => $first['tracking_number'] ?? '',
            '_aftership_tracking_provider_name' => $first['slug'] ?? '',
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
        $this->client()->put($this->orderUrl($providerOrderId), $payload)->throw();
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()
            ->withHeaders(['User-Agent' => self::UserAgent])
            ->withBasicAuth((string) config('services.woocommerce.consumer_key'), (string) config('services.woocommerce.consumer_secret'))
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
