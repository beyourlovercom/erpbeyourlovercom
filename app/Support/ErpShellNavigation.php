<?php

namespace App\Support;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\Gate;

class ErpShellNavigation
{
    public function for(User $user): array
    {
        return collect(config('erp_shell_navigation'))
            ->filter(fn (array $item) => Gate::forUser($user)->allows($item['permission']))
            ->map(fn (array $item) => $this->serialize($user, $item))
            ->values()->all();
    }

    private function serialize(User $user, array $item): array
    {
        $children = collect($item['children'] ?? [])
            ->filter(fn (array $child) => empty($child['permission']) || Gate::forUser($user)->allows($child['permission']))
            ->map(fn (array $child) => $this->serialize($user, $child))
            ->values()->all();

        return [
            'key' => $item['key'],
            'label' => $item['label'],
            'emoji' => $item['emoji'] ?? null,
            'path' => $item['path'],
            'route' => $item['route'],
            'placeholder' => $item['placeholder'],
            'children' => $children,
        ];
    }
}
