<?php

namespace App\Modules\Orders\Application\Inbound;

use App\Modules\Orders\Application\Contracts\WooCommerceOrderGateway;
use App\Modules\Orders\Application\OrderService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

final class WooCommerceInboundService
{
    private const PER_PAGE = 100;
    private const MAX_PAGES = 100;
    private const OVERLAP_MINUTES = 5;

    public function __construct(
        private readonly WooCommerceOrderGateway $gateway,
        private readonly WooCommerceOrderNormalizer $normalizer,
        private readonly OrderService $orders,
    ) {}

    public function descriptor(): array
    {
        $descriptor = $this->gateway->descriptor();
        $descriptor['last_import_at'] = $this->lastImportedAt($descriptor['connection_key']);
        return $descriptor;
    }

    public function probe(): array
    {
        return $this->gateway->probe();
    }

    public function importOne(int $providerOrderId, ?Authenticatable $actor): array
    {
        $payload = $this->gateway->fetchOrder($providerOrderId);
        return $this->importPayloads([$payload], $actor);
    }

    public function importIncremental(?Authenticatable $actor): array
    {
        $connectionKey = $this->gateway->descriptor()['connection_key'];
        $lastImportedAt = $this->lastImportedAt($connectionKey);
        if ($lastImportedAt === null) {
            throw new DomainException('尚无 WooCommerce 增量游标，请先执行单笔导入或历史导入。');
        }

        $modifiedAfter = CarbonImmutable::parse($lastImportedAt)->subMinutes(self::OVERLAP_MINUTES)->utc()->toIso8601String();
        return $this->importPages([
            'modified_after' => $modifiedAfter,
            'status' => 'any',
            'per_page' => self::PER_PAGE,
        ], $actor);
    }

    public function importHistorical(string $from, string $to, ?Authenticatable $actor): array
    {
        $after = CarbonImmutable::parse($from)->startOfDay()->utc()->toIso8601String();
        $before = CarbonImmutable::parse($to)->endOfDay()->utc()->toIso8601String();
        return $this->importPages([
            'after' => $after,
            'before' => $before,
            'status' => 'any',
            'orderby' => 'date',
            'order' => 'asc',
            'per_page' => self::PER_PAGE,
        ], $actor);
    }

    private function importPages(array $baseQuery, ?Authenticatable $actor): array
    {
        $summary = $this->emptySummary();
        $page = 1;
        $totalPages = 1;

        do {
            $result = $this->gateway->fetchOrders([...$baseQuery, 'page' => $page]);
            if ((int) $result['total_pages'] > self::MAX_PAGES) {
                throw new DomainException('WooCommerce 导入范围超过单次 10000 单上限，请缩小历史日期范围。');
            }
            $pageSummary = $this->importPayloads($result['items'], $actor);
            $summary = $this->mergeSummary($summary, $pageSummary);
            $totalPages = max(1, (int) $result['total_pages']);
            $page++;
        } while ($page <= $totalPages);

        $summary['pages'] = $page - 1;
        return $summary;
    }

    /** @param list<array<string,mixed>> $payloads */
    private function importPayloads(array $payloads, ?Authenticatable $actor): array
    {
        $summary = $this->emptySummary();
        $connectionKey = $this->gateway->descriptor()['connection_key'];

        foreach ($payloads as $payload) {
            $summary['seen']++;
            $reference = (string) ($payload['id'] ?? '?');
            try {
                $normalized = $this->normalizer->normalize($payload, $connectionKey);
                $result = $this->orders->upsertInbound($normalized, $actor);
                $summary[$result['created'] ? 'created' : 'updated']++;
            } catch (Throwable $e) {
                $summary['failed']++;
                if (count($summary['errors']) < 20) {
                    $summary['errors'][] = ['reference' => $reference, 'message' => $e->getMessage()];
                }
            }
        }

        return $summary;
    }

    private function lastImportedAt(string $connectionKey): ?string
    {
        $referenceClass = 'App\\Modules\\Orders\\Infrastructure\\Models\\OrderExternalReference';
        $value = $referenceClass::query()
            ->where('provider', 'woocommerce')
            ->where('connection_key', $connectionKey)
            ->max('provider_modified_at');

        return $value ? CarbonImmutable::parse($value)->toIso8601String() : null;
    }

    private function emptySummary(): array
    {
        return ['seen' => 0, 'created' => 0, 'updated' => 0, 'failed' => 0, 'pages' => 0, 'errors' => []];
    }

    private function mergeSummary(array $left, array $right): array
    {
        foreach (['seen', 'created', 'updated', 'failed'] as $key) {
            $left[$key] += $right[$key];
        }
        $left['errors'] = array_slice([...$left['errors'], ...$right['errors']], 0, 20);
        return $left;
    }
}
