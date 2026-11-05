<?php

namespace App\Modules\Orders\Application;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Fail-closed, explicitly approved cohort for gradual Production rollout.
 * Non-Production environments retain the existing local/staging UAT behavior.
 */
final class OrderOmsAvailability
{
    public static function grid(?Authenticatable $actor): bool
    {
        if ($actor === null) return false;
        if (! app()->environment('production')) return true;
        if (config('oms_grid.production_enabled') !== true) return false;

        $ids = array_filter(array_map(
            static fn (string $id): ?int => ctype_digit(trim($id)) && (int) trim($id) > 0
                ? (int) trim($id) : null,
            explode(',', (string) config('oms_grid.production_user_ids', '')),
        ));

        // Never permit all users from an empty/invalid allowlist.
        return $ids !== [] && in_array((int) $actor->getAuthIdentifier(), $ids, true);
    }

    public static function export(?Authenticatable $actor): bool
    {
        return self::grid($actor)
            && (! app()->environment('production') || config('oms_grid.production_export_enabled') === true);
    }

    public static function import(?Authenticatable $actor): bool
    {
        return self::grid($actor)
            && (! app()->environment('production') || config('oms_grid.production_import_enabled') === true);
    }
}
