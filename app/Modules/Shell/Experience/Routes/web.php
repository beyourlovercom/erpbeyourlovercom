<?php

use App\Modules\Shell\Application\LegacyErpNavigationPaths;
use App\Modules\Shell\Experience\Controllers\ErpShellController;
use App\Modules\Shell\Experience\Controllers\ShellPlaceholderController;
use Illuminate\Support\Facades\Route;

$legacyPaths = app(LegacyErpNavigationPaths::class);
$formalPaths = collect(config('erp_shell_navigation'))->where('placeholder', true)->pluck('path')->map($legacyPaths->normalize(...))->all();

foreach (collect(config('erp_shell_navigation'))->where('placeholder', true) as $module) {
    Route::middleware(['web', 'auth'])->get($module['path'], ShellPlaceholderController::class)
        ->defaults('module', $module)
        ->name($module['route']);
}

Route::middleware(['web', 'auth', 'can:prototype.view'])->group(function () use ($formalPaths, $legacyPaths): void {
    Route::get('/kol/orders', [ErpShellController::class, 'showOrderList'])->name('kol.orders');
    Route::get('/kol/orders/{orderNumber}', [ErpShellController::class, 'showOrderDetail'])
        ->where('orderNumber', 'KOL[0-9]{8}-[0-9]{3}')
        ->name('kol.orders.show');

    foreach (config('erp_navigation') as $module => $definition) {
        $register = function (array $items) use (&$register, $module, $formalPaths, $legacyPaths): void {
            foreach ($items as $key => $item) {
                if ($module === 'kol' && $key === 'orders') {
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
