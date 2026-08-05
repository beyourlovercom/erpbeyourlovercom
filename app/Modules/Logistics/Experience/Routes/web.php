<?php

use App\Modules\Logistics\Experience\Controllers\LogisticsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/logistics', [LogisticsController::class, 'index'])->middleware('can:logistics.view')->name('shell.logistics');
    Route::get('/logistics/pending', [LogisticsController::class, 'index'])->middleware('any.permission:logistics.view,prototype.view')->name('logistics.pending');
    Route::get('/logistics/orders/{order}/payload', [LogisticsController::class, 'payload'])->middleware('can:logistics.view')->name('logistics.payload');
    Route::post('/logistics/orders/{order}/ship', [LogisticsController::class, 'markShipped'])->middleware('can:logistics.operate')->name('logistics.ship');
});
