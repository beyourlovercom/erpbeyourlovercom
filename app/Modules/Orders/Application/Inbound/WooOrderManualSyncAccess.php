<?php

namespace App\Modules\Orders\Application\Inbound;

use App\Modules\Identity\Application\RolePreset;
use App\Modules\Identity\Infrastructure\Models\User;

/**
 * Minimal authority for today's Woo order import.
 * The Operations preset may import orders but receives no general sync.operate right.
 * Custom-permission accounts must have an explicit sync.operate grant.
 */
final class WooOrderManualSyncAccess
{
    public function allows(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->can('sync.operate')) {
            return true;
        }

        return $user->permission_mode !== 'custom'
            && $user->can('orders.view')
            && $user->can('sync.view')
            && $user->roles()->where('key', RolePreset::Operations->roleKey())->exists();
    }
}
