<?php

use App\Modules\Sync\Experience\Controllers\ManualSyncController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/sync', [ManualSyncController::class, 'index'])->middleware('can:sync.view')->name('shell.sync');
    Route::get('/sync/orders/{order}/payload', [ManualSyncController::class, 'payload'])->middleware('can:sync.view')->name('sync.payload');
    Route::post('/sync/orders/{order}/complete', [ManualSyncController::class, 'complete'])->middleware('can:sync.operate')->name('sync.complete');
    Route::post('/sync/orders/{order}/reopen', [ManualSyncController::class, 'reopen'])->middleware('can:sync.operate')->name('sync.reopen');
    Route::post('/sync/orders/{order}/return', [ManualSyncController::class, 'return'])->middleware('can:sync.operate')->name('sync.return');
});
