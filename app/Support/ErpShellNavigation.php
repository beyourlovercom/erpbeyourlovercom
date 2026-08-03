<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ErpShellNavigation
{
    public function for(User $user): array
    {
        return collect(config('erp_shell_navigation'))
            ->filter(fn (array $item) => Gate::forUser($user)->allows($item['permission']))
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
