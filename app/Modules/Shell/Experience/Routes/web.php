<?php

use App\Modules\Shell\Application\LegacyErpNavigationPaths;
use App\Modules\Shell\Experience\Controllers\ErpShellController;
use App\Modules\Shell\Experience\Controllers\ShellPlaceholderController;
use Illuminate\Support\Facades\Route;

$legacyPaths = app(LegacyErpNavigationPaths::class);
$formalPaths = collect(config('erp_shell_navigation'))->pluck('path')->map($legacyPaths->normalize(...))->all();
$implementedPaths = [
    '/orders', '/customers/orders', '/customers/orders/create', '/kol/orders', '/kol/orders/create',
    '/dashboard', '/logistics', '/sync', '/kol', '/products', '/accounting/order-costs', '/settings/users', '/settings/roles-permissions',
    '/inventory', '/inventory/warehouses', '/guides', '/guides/videos', '/guides/manuals', '/guides/changelog',
];

foreach (collect(config('erp_shell_navigation'))->reject(fn (array $module): bool => in_array($module['path'], $implementedPaths, true)) as $module) {
    Route::middleware(['web', 'auth'])->get($module['path'], ShellPlaceholderController::class)
        ->defaults('module', $module)
        ->name($module['route']);
}

Route::middleware(['web', 'auth', 'can:prototype.view'])->group(function () use ($formalPaths, $legacyPaths, $implementedPaths): void {
    foreach (config('erp_navigation') as $module => $definition) {
        $register = function (array $items) use (&$register, $module, $formalPaths, $legacyPaths, $implementedPaths): void {
            foreach ($items as $key => $item) {
                if (in_array($item['path'] ?? null, $implementedPaths, true) || ($module === 'logistics' && $key === 'pending')) {
                    continue;
                }
                if (! empty($item['path']) && ! in_array($legacyPaths->normalize($item['path']), $formalPaths, true)) {
                    Route::get($item['path'], [ErpShellController::class, 'show'])->defaults('module', $module)->name($module.'.'.$key);
                }
                if (! empty($item['children'])) {
                    $register($item['children']);
                }
            }
        };
        $register($definition['children']);
    }
});
