<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Identity\Application\RolePreset;
use App\Modules\Identity\Infrastructure\Models\User;

final class LogisticsTrackingPushAccess
{
    public function allows(?User $user): bool
    {
        if (! $user || ! $user->can('logistics.view') || ! $user->can('sync.view')) {
            return false;
        }

        if ($user->can('sync.operate') && $user->can('logistics.operate')) {
            return true;
        }

        // Operations may push eligible shipped-order tracking, not control general Sync.
        return $user->permission_mode !== 'custom'
            && $user->roles()->where('key', RolePreset::Operations->roleKey())->exists();
    }
}
