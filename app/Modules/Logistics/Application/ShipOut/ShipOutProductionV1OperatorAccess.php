<?php

namespace App\Modules\Logistics\Application\ShipOut;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * ShipOut Production V1-only authorization. Explicit, fail-closed staff
 * allowlist prevents granting Vita global logistics.operate permissions.
 * R10 Canary, Sandbox, 3SM and tracking write routes are unaffected.
 */
final class ShipOutProductionV1OperatorAccess
{
    public function allows(?Authenticatable $actor): bool
    {
        if (! $actor instanceof User || ! $actor->is_active
            || ! $actor->hasPermission('logistics.view')) {
            return false;
        }

        $raw = trim((string) config('shipout.production_v1_operator_user_ids', ''));
        if ($raw === '' || preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/D', $raw) !== 1) {
            return false;
        }

        $ids = array_map('intval', explode(',', $raw));
        if (count($ids) !== 3 || count(array_unique($ids)) !== 3) {
            return false;
        }

        return in_array((int) $actor->id, $ids, true);
    }
}
