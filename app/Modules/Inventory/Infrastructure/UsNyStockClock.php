<?php

namespace App\Modules\Inventory\Infrastructure;

use App\Modules\Inventory\Infrastructure\Models\WarehouseInventory;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** MySQL TIMESTAMP is session-zone dependent; compare absolute epoch seconds. */
final class UsNyStockClock
{
    public static function utc(Closure $work): mixed
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return $work();
        }
        $original = (string) DB::scalar('SELECT @@session.time_zone');
        DB::statement("SET time_zone = '+00:00'");
        try {
            return $work();
        } finally {
            DB::statement('SET time_zone = ?', [$original]);
        }
    }

    public static function withEpoch(Builder $query, array $columns = ['warehouse_inventory.*']): Builder
    {
        $query->select($columns);
        if ($query->getModel()->getConnection()->getDriverName() === 'mysql') {
            $query->selectRaw('UNIX_TIMESTAMP(warehouse_inventory.provider_stock_synced_at) AS ny_stock_epoch');
        }

        return $query;
    }

    public static function fresh(?WarehouseInventory $stock, string $warehouseCode = 'US-NY'): bool
    {
        if (! $stock || $stock->provider_available_quantity === null) {
            return false;
        }
        $epoch = $stock->getConnection()->getDriverName() === 'mysql'
            ? $stock->getAttribute('ny_stock_epoch')
            : $stock->provider_stock_synced_at?->getTimestamp();
        if ($epoch === null || ! is_numeric($epoch)) {
            return false;
        }
        $age = now()->getTimestamp() - (int) $epoch;

        // CA02 has an Owner-approved daily mirror plus one hour of scheduler grace.
        // All other warehouses retain the existing two-hour proof window.
        $maxAge = strtoupper($warehouseCode) === 'CA' ? 25 * 3600 : 7200;

        return $age >= 0 && $age <= $maxAge;
    }
}
