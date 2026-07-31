<?php

namespace App\Support;

use App\Models\User;

class ErpShellNavigation
{
    public function for(User $user): array
    {
        $user->loadMissing('roles.permissions');
        $isSuperAdmin = $user->roles->contains('key', AuthorizationKeys::SuperAdmin->value);
        $permissions = $user->roles->flatMap->permissions->pluck('key')->unique();

        return collect(config('erp_shell_navigation'))
            ->filter(fn (array $item) => $isSuperAdmin || $permissions->contains($item['permission']))
            ->map(fn (array $item) => [
                'key' => $item['key'],
                'label' => $item['label'],
                'path' => $item['path'],
                'route' => $item['route'],
                'placeholder' => $item['placeholder'],
                'children' => $item['children'] ?? [],
            ])->values()->all();
    }
}
