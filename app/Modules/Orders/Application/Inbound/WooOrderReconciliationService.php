<?php

namespace App\Modules\Orders\Application\Inbound;

use App\Modules\Orders\Application\Contracts\WooCommerceOrderGateway;
use App\Modules\Orders\Infrastructure\Models\OrderExternalReference;
use Carbon\CarbonImmutable;
use DomainException;

final class WooOrderReconciliationService
{
    private const MAX_PAGES = 10;

    public function __construct(private readonly WooCommerceOrderGateway $gateway) {}

    /** Read-only source comparison; no import, no checkpoint changes, no persisted customer data. */
    public function today(): array
    {
        $descriptor = $this->gateway->descriptor();
        if (! $descriptor['configured']) {
            throw new DomainException('WooCommerce 尚未配置');
        }

        $now = CarbonImmutable::now('UTC');
        $start = $now->startOfDay()->toIso8601String();
        $base = [
            'after' => $start,
            'before' => $now->toIso8601String(),
            'status' => 'any',
            'orderby' => 'date',
            'order' => 'asc',
            'per_page' => 100,
        ];
        $references = [];
        $skipped = 0;
        $page = 1;
        do {
            $result = $this->gateway->fetchOrders([...$base, 'page' => $page]);
            if ((int) $result['total_pages'] > self::MAX_PAGES) {
                return ['status' => 'incomplete', 'unimported_count' => null, 'message' => '今日订单超过只读核对上限（1000单），无法给出准确数量'];
            }
            foreach ($result['items'] as $payload) {
                if (! is_array($payload)) {
                    continue;
                }
                if ($this->isIgnored($payload)) {
                    $skipped++;
                    continue;
                }
                if (isset($payload['id']) && is_numeric($payload['id'])) {
                    $references[] = (string) $payload['id'];
                }
            }
            $totalPages = max(1, (int) $result['total_pages']);
            $page++;
        } while ($page <= $totalPages);

        $references = array_values(array_unique($references));
        $known = $references === [] ? 0 : OrderExternalReference::query()
            ->where('provider', 'woocommerce')
            ->where('connection_key', (string) $descriptor['connection_key'])
            ->whereIn('reference', $references)
            ->distinct()->count('reference');

        return [
            'status' => 'complete',
            'window_start' => $start,
            'checked_at' => $now->toIso8601String(),
            'source_count' => count($references),
            'in_erp_count' => $known,
            'unimported_count' => count($references) - $known,
            'excluded_count' => $skipped,
        ];
    }

    private function isIgnored(array $payload): bool
    {
        if (strtolower(trim((string) ($payload['created_via'] ?? ''))) === 'admin'
            && is_array($payload['line_items'] ?? null)
            && $payload['line_items'] === []) {
            return true;
        }
        if (strtolower(trim((string) ($payload['billing']['email'] ?? ''))) === 'byl-p13@example.invalid') {
            return true;
        }
        foreach (($payload['meta_data'] ?? []) as $meta) {
            if (is_array($meta) && ($meta['key'] ?? '') === '_byl_dev_sandbox_p13'
                && strtolower(trim((string) ($meta['value'] ?? ''))) === 'yes') {
                return true;
            }
        }

        return false;
    }
}
