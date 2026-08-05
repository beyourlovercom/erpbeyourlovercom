<?php

use App\Modules\Finance\Experience\Controllers\OrderAnalysisController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/accounting/order-costs', [OrderAnalysisController::class, 'index'])->middleware('can:analysis.view')->name('accounting.order-costs');
    Route::get('/accounting/order-costs/export', [OrderAnalysisController::class, 'export'])->middleware('can:analysis.view')->name('analysis.export');
    Route::put('/accounting/order-costs/{order}', [OrderAnalysisController::class, 'update'])->middleware('can:analysis.view')->name('analysis.update');
});
