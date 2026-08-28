<?php

use App\Modules\Sync\Experience\Controllers\ManualSyncController;
use App\Modules\Sync\Experience\Controllers\WooCommerceTrackingOutboundController;
use App\Modules\Sync\Experience\Controllers\SyncOrchestrationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/sync', [ManualSyncController::class, 'index'])->middleware('can:sync.view')->name('shell.sync');
    Route::get('/sync/orders/{order}/payload', [ManualSyncController::class, 'payload'])->middleware('can:sync.view')->name('sync.payload');
    Route::post('/sync/orders/{order}/complete', [ManualSyncController::class, 'complete'])->middleware('can:sync.operate')->name('sync.complete');
    Route::post('/sync/orders/{order}/reopen', [ManualSyncController::class, 'reopen'])->middleware('can:sync.operate')->name('sync.reopen');
    Route::post('/sync/orders/{order}/return', [ManualSyncController::class, 'return'])->middleware('can:sync.operate')->name('sync.return');
    Route::post('/sync/orders/{order}/tracking-outbound', [WooCommerceTrackingOutboundController::class, 'push'])->middleware('can:sync.operate')->name('sync.tracking-outbound');
    Route::post('/sync/orchestration/tracking/selected', [SyncOrchestrationController::class, 'trackingSelected'])->middleware('can:sync.operate')->name('sync.orchestration.tracking-selected');
    Route::post('/sync/orchestration/tracking/all-pending', [SyncOrchestrationController::class, 'trackingAllPending'])->middleware('can:sync.operate')->name('sync.orchestration.tracking-all-pending');
    Route::post('/sync/orchestration/retry', [SyncOrchestrationController::class, 'retryPending'])->middleware('can:sync.operate')->name('sync.orchestration.retry');
    Route::post('/sync/orchestration/recover-stale', [SyncOrchestrationController::class, 'recoverStale'])->middleware('can:sync.operate')->name('sync.orchestration.recover-stale');
    Route::post('/sync/orchestration/reconcile', [SyncOrchestrationController::class, 'reconcileSelected'])->middleware('can:sync.operate')->name('sync.orchestration.reconcile');
    Route::get('/sync/runs/{run}', [SyncOrchestrationController::class, 'show'])->middleware('can:sync.view')->name('sync.runs.show');
});
