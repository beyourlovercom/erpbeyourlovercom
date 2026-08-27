<?php

namespace App\Modules\Shell\Application;

use App\Modules\Inventory\Application\Contracts\InventoryRepository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

final class ErpShellNavigation
{
    public function __construct(private readonly InventoryRepository $inventory) {}

    public function for(Authenticatable $user): array
    {
        return collect(config('erp_shell_navigation'))
            ->filter(fn (array $item) => Gate::forUser($user)->allows($item['permission']))
            ->map(function (array $item) use ($user): array {
                $serialized = $this->serialize($user, $item);
                if ($item['key'] === 'inventory') {
                    $serialized['children'] = $this->withWarehouseShortcuts($serialized['children']);
                }

                return $serialized;
            })
            ->values()->all();
    }

    private function withWarehouseShortcuts(array $children): array
    {
        $shortcuts = collect($this->inventory->warehouseList())
            ->filter(fn (array $warehouse): bool => (bool) ($warehouse['is_active'] ?? false))
            ->map(fn (array $warehouse): array => [
                'key' => 'inventory-warehouse-'.$warehouse['id'],
                'label' => $warehouse['name'],
                'emoji' => null,
                'path' => '/inventory/'.$warehouse['slug'],
                'route' => 'inventory.warehouse.show',
                'placeholder' => false,
                'group' => false,
                'children' => [],
            ])
            ->values()->all();

        $result = [];
        foreach ($children as $child) {
            $result[] = $child;
            if ($child['key'] === 'inventory-list') {
                $result = [...$result, ...$shortcuts];
            }
        }

        return $result;
    }

    private function serialize(Authenticatable $user, array $item): array
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
            'group' => $item['group'] ?? false,
            'children' => $children,
        ];
    }
}
