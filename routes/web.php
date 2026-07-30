<?php

use App\Http\Controllers\ErpShellController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/logistics/pending'));

foreach (config('erp_navigation') as $module => $definition) {
    $register = function (array $items) use (&$register, $module): void {
        foreach ($items as $key => $item) {
            if (!empty($item['path'])) {
                Route::get($item['path'], [ErpShellController::class, 'show'])->defaults('module', $module)->name($module.'.'.$key);
            }
            if (!empty($item['children'])) $register($item['children']);
        }
    };
    $register($definition['children']);
}
