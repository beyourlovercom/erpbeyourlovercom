<?php

use App\Http\Controllers\ErpShellController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Middleware\HandleInertiaRequests;
use Inertia\Inertia;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Gate;

Route::get('/', [AuthController::class, 'root'])->name('root');
Route::get('/login', [AuthController::class, 'login'])->middleware('guest')->name('login');
Route::middleware(['auth', 'can:dashboard.view'])->get('/dashboard', DashboardController::class)->name('dashboard');
$shellModules = collect(config('erp_shell_navigation'))->where('placeholder', true);
foreach ($shellModules as $module) {
    Route::get($module['path'], function () use ($module) {
        if (Gate::allows($module['permission'])) {
            return Inertia::render('Shell/Placeholder', [
                'module' => ['key' => $module['key'], 'label' => $module['label']],
            ]);
        }
        if (Gate::allows('prototype.view') && array_key_exists($module['key'], config('erp_navigation'))) {
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
    $formalPaths = collect(config('erp_shell_navigation'))->where('placeholder', true)->pluck('path')->all();
    $register = function (array $items) use (&$register, $module, $formalPaths): void {
        foreach ($items as $key => $item) {
            if ($module === 'kol' && $key === 'orders') continue;
            if (!empty($item['path']) && !in_array($item['path'], $formalPaths, true)) {
                Route::get($item['path'], [ErpShellController::class, 'show'])->defaults('module', $module)->name($module.'.'.$key);
            }
            if (!empty($item['children'])) $register($item['children']);
        }
    };
    $register($definition['children']);
}
});
