<?php

use App\Modules\Finance\Experience\Controllers\FxSettingsController;
use App\Modules\Finance\Experience\Controllers\OrderAnalysisController;
use App\Modules\Finance\Experience\Controllers\ProfitDashboardController;
use App\Modules\Finance\Experience\Controllers\B2BTradeCashController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/settings/fx', [FxSettingsController::class, 'index'])->middleware('can:system.permissions.manage')->name('settings.fx');
    Route::put('/settings/fx', [FxSettingsController::class, 'update'])->middleware('can:system.permissions.manage')->name('settings.fx.update');
    Route::get('/accounting', [ProfitDashboardController::class, 'index'])->middleware('can:accounting.view')->name('accounting.index');
    Route::get('/accounting/order-costs', [OrderAnalysisController::class, 'index'])->middleware('can:analysis.view')->name('accounting.order-costs');
    Route::get('/accounting/order-costs/export', [OrderAnalysisController::class, 'export'])->middleware('can:analysis.view')->name('analysis.export');
    Route::get('/accounting/order-costs/{order}', [OrderAnalysisController::class, 'show'])->middleware('can:analysis.view')->name('analysis.show');
    Route::put('/accounting/order-costs/{order}', [OrderAnalysisController::class, 'update'])->middleware('can:analysis.view')->name('analysis.update');
});


// S3-G4: Finance-owned manual attestation; independent of Woo OrderFinancial.
Route::middleware(['web','auth','can:accounting.view'])->group(function (): void {
    Route::get('/accounting/b2b-trade-cash',[B2BTradeCashController::class,'index'])
        ->name('accounting.b2b-trade-cash.index');
    Route::get('/accounting/b2b-trade-cash/{tradeOrder}',[B2BTradeCashController::class,'show'])
        ->whereNumber('tradeOrder')->name('accounting.b2b-trade-cash.show');
});
Route::middleware(['web','auth','can:accounting.view','can:finance.b2b_cash_confirm'])->group(function (): void {
    Route::post('/accounting/b2b-trade-cash/{tradeOrder}/receipts/{evidence}',
        [B2BTradeCashController::class,'confirmReceipt'])
        ->whereNumber('tradeOrder')->whereNumber('evidence')
        ->name('accounting.b2b-trade-cash.receipts.confirm');
    Route::post('/accounting/b2b-trade-cash/{tradeOrder}/refunds/{cashEvent}',
        [B2BTradeCashController::class,'confirmRefund'])
        ->whereNumber('tradeOrder')->whereNumber('cashEvent')
        ->name('accounting.b2b-trade-cash.refunds.confirm');
});
