<?php

namespace App\Modules\Sync\Application;

use App\Modules\Logistics\Infrastructure\Models\Shipment;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * Reconstruct a shipment's real UTC instant from the canonical DB TIMESTAMP.
 *
 * On MySQL, TIMESTAMP is returned in the connection's timezone (SYSTEM can
 * be America/Los_Angeles even when Laravel runs in UTC). Never parse that
 * rendered wall clock as UTC. UNIX_TIMESTAMP returns the instant before the
 * session timezone rendering. SQLite test timestamps are stored in UTC.
 */
final class ShipmentUtcTimestamp
{
    public const string CONTRACT = 'mysql-timestamp-epoch-utc-v1';

    public static function forShipment(Shipment $shipment): CarbonImmutable
    {
        if (! $shipment->exists || $shipment->shipped_at === null) {
            throw new DomainException('Shipment 缺少持久化发货时间，禁止推送 Tracking。');
        }

        $connection = $shipment->getConnection();
        if ($connection->getDriverName() === 'mysql') {
            $row = $connection->table($shipment->getTable())
                ->where('id', $shipment->getKey())
                ->selectRaw('UNIX_TIMESTAMP(shipped_at) as utc_epoch')
                ->first();

            if (! $row || ! is_numeric($row->utc_epoch)) {
                throw new DomainException('MySQL Shipment UTC 时间无法核验，禁止推送 Tracking。');
            }

            return self::fromEpoch((int) $row->utc_epoch);
        }

        // SQLite's DATETIME has no session conversion; tests use UTC storage.
        return CarbonImmutable::parse($shipment->shipped_at)->utc();
    }

    public static function fromEpoch(int $epoch): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC($epoch);
    }

    /**
     * Isolate pre-fix timezone-drift intents from the automatic retry runner.
     * Do not modify Outbox or silently turn 28 previously skipped records
     * into 28 remote Woo writes after upgrading the canonical date reader.
     *
     * This is a conservative extra guard, not evidence that WWW has Tracking.
     *
     * @param array<string,mixed> $oldPayload
     * @param array<string,mixed> $newPayload
     */
    public static function holdLegacyTimezoneIntent(
        array $oldPayload,
        array $newPayload,
        ?string $legacyModelUtcTime,
    ): bool {
        if (isset($oldPayload['shipped_at_contract'])) {
            return false;
        }

        $old = trim((string) ($oldPayload['shipped_at'] ?? ''));
        $canonical = trim((string) ($newPayload['shipped_at'] ?? ''));
        if ($old === '' || $old !== $canonical || $legacyModelUtcTime === null) {
            return false;
        }

        return $legacyModelUtcTime !== $canonical;
    }
}
