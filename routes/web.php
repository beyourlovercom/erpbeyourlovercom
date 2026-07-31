<?php

use App\Http\Controllers\ErpShellController;
use App\Http\Middleware\HandleInertiaRequests;
use Inertia\Inertia;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/logistics/pending'));
Route::get('/architecture/foundation', fn () => Inertia::render('Architecture/Foundation', [
    'title' => 'BYL ERP Foundation',
    'status' => 'ARCHITECTURE VALIDATION',
]))->middleware(HandleInertiaRequests::class)->name('architecture.foundation');
Route::get('/kol/orders', [ErpShellController::class, 'showOrderList'])->name('kol.orders');
Route::get('/kol/orders/{orderNumber}', [ErpShellController::class, 'showOrderDetail'])
    ->where('orderNumber', 'KOL[0-9]{8}-[0-9]{3}')
    ->name('kol.orders.show');

foreach (config('erp_navigation') as $module => $definition) {
    $register = function (array $items) use (&$register, $module): void {
        foreach ($items as $key => $item) {
            if ($module === 'kol' && $key === 'orders') continue;
            if (!empty($item['path'])) {
                Route::get($item['path'], [ErpShellController::class, 'show'])->defaults('module', $module)->name($module.'.'.$key);
            }
            if (!empty($item['children'])) $register($item['children']);
        }
    };
    $register($definition['children']);
}
