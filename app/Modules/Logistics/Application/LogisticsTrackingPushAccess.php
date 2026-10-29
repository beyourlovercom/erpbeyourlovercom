<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Identity\Application\RolePreset;
use App\Modules\Identity\Infrastructure\Models\User;

final class LogisticsTrackingPushAccess
{
    public function allows(?User $user): bool
    {
        if (! in_array((string) config('tracking_sync_v2.manual_mode', 'off'), ['canary', 'general'], true)) {
            return false;
        }
        if (! $user || ! $user->can('logistics.view') || ! $user->can('sync.view')) {
            return false;
        }

        if ((string) config('tracking_sync_v2.manual_mode') === 'general') {
            // Production manual rollout is limited to formal, role-inherited
            // Operations, Logistics and Administrator. Historical UAT roles
            // with broad permission keys must never gain live Woo write access.
            return $user->is_active
                && $user->permission_mode === 'role'
                && $user->roles()->whereIn('key', [
                    RolePreset::Operations->roleKey(),
                    RolePreset::Logistics->roleKey(),
                    RolePreset::Administrator->roleKey(),
                ])->exists();
        }

        if ($user->can('sync.operate') && $user->can('logistics.operate')) {
            return true;
        }

        // Canary remains compatible with the original verified role rules.
        return $user->permission_mode !== 'custom'
            && $user->roles()->where('key', RolePreset::Operations->roleKey())->exists();
    }
}
