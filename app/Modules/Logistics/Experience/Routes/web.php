<?php

use App\Modules\Logistics\Experience\Controllers\CarrierController;
use App\Modules\Logistics\Experience\Controllers\LogisticsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/logistics', [LogisticsController::class, 'index'])->middleware('can:logistics.view')->name('shell.logistics');
    Route::get('/logistics/pending', [LogisticsController::class, 'index'])->middleware('any.permission:logistics.view,prototype.view')->name('logistics.pending');
    Route::get('/logistics/carriers', [CarrierController::class, 'index'])->middleware('can:logistics.view')->name('logistics.carriers');
    Route::get('/logistics/carriers/{carrier}', [CarrierController::class, 'show'])->middleware('can:logistics.view')->name('logistics.carriers.show');
    Route::post('/logistics/orders/{order}/draft', [LogisticsController::class, 'saveDraft'])->middleware('can:logistics.operate')->name('logistics.draft');
    Route::get('/logistics/orders/{order}/payload', [LogisticsController::class, 'payload'])->middleware('can:logistics.view')->name('logistics.payload');
    Route::post('/logistics/orders/{order}/ship', [LogisticsController::class, 'markShipped'])->middleware('can:logistics.operate')->name('logistics.ship');
    Route::post('/logistics/orders/{order}/reopen', [LogisticsController::class, 'reopen'])->middleware('can:logistics.operate')->name('logistics.reopen');
});
