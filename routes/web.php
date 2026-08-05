<?php

use App\Http\Controllers\ErpShellController;
use App\Http\Controllers\DashboardController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\LegacyErpNavigationPaths;
use Inertia\Inertia;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Gate;

Route::middleware(['auth', 'can:dashboard.view'])->get('/dashboard', DashboardController::class)->name('dashboard');
$shellModules = collect(config('erp_shell_navigation'))->where('placeholder', true);
$legacyPathSupport = app(LegacyErpNavigationPaths::class);
$legacyPaths = $legacyPathSupport->all(config('erp_navigation'));
foreach ($shellModules as $module) {
    Route::get($module['path'], function () use ($module, $legacyPaths, $legacyPathSupport) {
        if (Gate::allows($module['permission'])) {
            return Inertia::render('Shell/Placeholder', [
                'module' => ['key' => $module['key'], 'label' => $module['label']],
            ]);
        }
        if (Gate::allows('prototype.view') && in_array($legacyPathSupport->normalize($module['path']), $legacyPaths, true)) {
            return app(ErpShellController::class)->show($module['key'], trim($module['path'], '/'));
        }
        abort(403);
    })->middleware('auth')->name($module['route']);
}
Route::get('/architecture/foundation', fn () => Inertia::render('Architecture/Foundation', [
    'title' => 'BYL ERP Foundation',
    'status' => 'ARCHITECTURE VALIDATION',
]))->middleware([HandleInertiaRequests::class, 'auth', 'can:architecture.foundation.view'])->name('architecture.foundation');
Route::middleware(['auth', 'can:prototype.view'])->group(function (): void {
Route::get('/kol/orders', [ErpShellController::class, 'showOrderList'])->name('kol.orders');
Route::get('/kol/orders/{orderNumber}', [ErpShellController::class, 'showOrderDetail'])
    ->where('orderNumber', 'KOL[0-9]{8}-[0-9]{3}')
    ->name('kol.orders.show');

foreach (config('erp_navigation') as $module => $definition) {
    $legacyPathSupport = app(LegacyErpNavigationPaths::class);
    $formalPaths = collect(config('erp_shell_navigation'))->where('placeholder', true)
        ->pluck('path')->map($legacyPathSupport->normalize(...))->all();
    $register = function (array $items) use (&$register, $module, $formalPaths, $legacyPathSupport): void {
        foreach ($items as $key => $item) {
            if ($module === 'kol' && $key === 'orders') continue;
            if (!empty($item['path']) && !in_array($legacyPathSupport->normalize($item['path']), $formalPaths, true)) {
                Route::get($item['path'], [ErpShellController::class, 'show'])->defaults('module', $module)->name($module.'.'.$key);
            }
            if (!empty($item['children'])) $register($item['children']);
        }
    };
    $register($definition['children']);
}
});
