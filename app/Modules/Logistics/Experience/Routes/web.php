<?php

use App\Modules\Logistics\Experience\Controllers\CarrierController;
use App\Modules\Logistics\Experience\Controllers\ChinaSmallPacketBarcode69Controller;
use App\Modules\Logistics\Experience\Controllers\DomesticInlineTrackingController;
use App\Modules\Logistics\Experience\Controllers\DomesticLogisticsController;
use App\Modules\Logistics\Experience\Controllers\HistoricalImportController;
use App\Modules\Logistics\Experience\Controllers\LogisticsController;
use App\Modules\Logistics\Experience\Controllers\LogisticsTrackingPushController;
use App\Modules\Logistics\Experience\Controllers\OverseaWarehouseController;
use App\Modules\Logistics\Experience\Controllers\ShipOutMorePlusController;
use App\Modules\Logistics\Experience\Controllers\ShipOutProductionBatchController;
use App\Modules\Logistics\Experience\Controllers\ShipOutProductionV1Controller;
use App\Modules\Logistics\Experience\Controllers\XlwmsReshipmentController;
use App\Modules\Logistics\Experience\Controllers\XlwmsSingleOrderController;
use App\Modules\Logistics\Experience\Controllers\XlwmsWarehouseBatchController;
use App\Modules\Logistics\Experience\Middleware\RejectXlwmsAutomaticCreate;
use App\Modules\Logistics\Experience\Middleware\RequireFulfillmentAllocation;
use App\Modules\Logistics\Experience\Middleware\RequireLogisticsExecution;
use App\Modules\Logistics\Experience\Middleware\RequireLogisticsReview;
use App\Modules\Logistics\Experience\Middleware\RequireShipOutProductionV1Operator;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    $execution = ['can:logistics.operate', RequireLogisticsExecution::class];
    $review = [RequireLogisticsReview::class];
    $allocation = [RequireFulfillmentAllocation::class];
    Route::get('/logistics', fn () => redirect()->route('logistics.wait-stock'))->middleware('can:logistics.view')->name('shell.logistics');
    Route::get('/logistics/pending', fn () => redirect()->route('logistics.wait-stock'))->middleware('can:logistics.view')->name('logistics.pending');
    Route::get('/logistics/wait-stock', [LogisticsController::class, 'waitStock'])->middleware('can:logistics.view')->name('logistics.wait-stock');
    Route::get('/logistics/china/orders/{order}/barcode-69', ChinaSmallPacketBarcode69Controller::class)->middleware('can:logistics.view')->whereNumber('order')->name('logistics.china.barcode-69');
    Route::get('/logistics/china', [DomesticLogisticsController::class, 'index'])->name('logistics.china');
    Route::post('/logistics/china/orders/{order}/carrier-service', [DomesticInlineTrackingController::class, 'selectCarrierService'])
        ->middleware($execution)->whereNumber('order')->name('logistics.china.carrier-service');
    Route::post('/logistics/china/orders/{order}/tracking-draft', [DomesticInlineTrackingController::class, 'save'])
        ->middleware($execution)->whereNumber('order')->name('logistics.china.tracking-draft');
    Route::post('/logistics/china/orders/{order}/quick-ship', [DomesticInlineTrackingController::class, 'ship'])
        ->middleware($execution)->whereNumber('order')->name('logistics.china.quick-ship');
    Route::post('/logistics/tracking-outbound/selected', [LogisticsTrackingPushController::class, 'selected'])
        ->middleware(['can:logistics.view', 'throttle:10,1'])->name('logistics.tracking-outbound.selected');
    Route::get('/logistics/china/today/export', [DomesticLogisticsController::class, 'exportTodayOrders'])->middleware('can:logistics.view')->name('logistics.china.today.export');
    Route::get('/logistics/china/export/{provider}', [DomesticLogisticsController::class, 'export'])->middleware('can:logistics.view')->whereIn('provider', ['4px', 'weship'])->name('logistics.china.export');
    Route::get('/logistics/china/pending-batch/export', [DomesticLogisticsController::class, 'exportPendingBatch'])->middleware('can:logistics.view')->name('logistics.china.pending-batch.export');
    Route::post('/logistics/china/tracking-import/preview', [DomesticLogisticsController::class, 'previewUnifiedTrackingReturn'])->middleware($execution)->name('logistics.china.tracking-import.unified-preview');
    Route::post('/logistics/china/pending-batch/tracking-import/preview', [DomesticLogisticsController::class, 'previewPendingBatch'])->middleware($execution)->name('logistics.china.pending-batch.tracking-import.preview');
    Route::post('/logistics/china/pending-batch/tracking-import/apply', [DomesticLogisticsController::class, 'applyPendingBatch'])->middleware($execution)->name('logistics.china.pending-batch.tracking-import.apply');
    Route::post('/logistics/china/{provider}/tracking-import/preview', [DomesticLogisticsController::class, 'previewTrackingReturn'])->middleware($execution)->whereIn('provider', ['4px', 'weship'])->name('logistics.china.tracking-import.preview');
    Route::post('/logistics/china/{provider}/tracking-import/apply', [DomesticLogisticsController::class, 'applyTrackingReturn'])->middleware($execution)->whereIn('provider', ['4px', 'weship'])->name('logistics.china.tracking-import.apply');
    Route::post('/logistics/china/batch-review', [DomesticLogisticsController::class, 'batchReview'])
        ->middleware(['can:domestic.review', 'throttle:15,1'])->name('logistics.china.batch-review');
    Route::post('/logistics/china/orders/{order}/approve', [DomesticLogisticsController::class, 'approve'])->middleware('can:domestic.review')->name('logistics.china.approve');
    Route::post('/logistics/china/orders/{order}/stockout', [DomesticLogisticsController::class, 'stockout'])->middleware('can:domestic.review')->name('logistics.china.stockout');
    Route::post('/logistics/china/orders/{order}/return-allocation', [DomesticLogisticsController::class, 'returnAllocation'])->middleware('can:domestic.review')->name('logistics.china.return-allocation');
    Route::post('/logistics/china/orders/{order}/restock', [DomesticLogisticsController::class, 'restock'])->middleware('can:domestic.review')->name('logistics.china.restock');
    Route::post('/logistics/china/orders/{order}/reject', [DomesticLogisticsController::class, 'reject'])->middleware('can:domestic.review')->name('logistics.china.reject');
    Route::post('/logistics/china/orders/{order}/reopen-review', [DomesticLogisticsController::class, 'reopenReview'])->middleware('can:domestic.review')->name('logistics.china.reopen-review');
    Route::post('/logistics/oversea/batch-review', [OverseaWarehouseController::class, 'batchReview'])
        ->middleware([...$review, 'throttle:15,1'])->name('logistics.oversea.batch-review');
    Route::post('/logistics/oversea/orders/{order}/stockout', [OverseaWarehouseController::class, 'stockout'])
        ->middleware($review)->whereNumber('order')->name('logistics.oversea.stockout');
    Route::post('/logistics/oversea/orders/{order}/restock', [OverseaWarehouseController::class, 'restock'])
        ->middleware($review)->whereNumber('order')->name('logistics.oversea.restock');
    Route::get('/logistics/oversea/{warehouse?}', [OverseaWarehouseController::class, 'index'])->middleware('can:logistics.view')->where('warehouse', 'us-ny|us-moreplus|us-3sm|us-sm|us-sj|usa-sj|de-tianyun|ca')->name('logistics.oversea');
    Route::get('/logistics/oversea/us-3sm/orders/{order}/manual-export',
        [OverseaWarehouseController::class, 'exportManualUsSmOrder'])
        ->middleware(['can:logistics.operate', RequireLogisticsExecution::class, 'throttle:30,1'])
        ->whereNumber('order')->name('logistics.xlwms.manual.export');
    Route::post('/logistics/oversea/us-3sm/xlwms/sync', [XlwmsWarehouseBatchController::class, 'sync'])
        ->middleware(['can:logistics.operate', RequireLogisticsExecution::class, RejectXlwmsAutomaticCreate::class, 'throttle:2,1'])
        ->name('logistics.xlwms.warehouse.sync');
    Route::post('/logistics/oversea/us-3sm/xlwms/refresh-statuses', [XlwmsWarehouseBatchController::class, 'refresh'])
        ->middleware(['can:logistics.operate', RequireLogisticsExecution::class, 'throttle:1,1'])
        ->name('logistics.xlwms.warehouse.refresh-statuses');
    Route::get('/logistics/oversea/us-3sm/orders/{order}/xlwms/preflight',
        [XlwmsSingleOrderController::class, 'inlinePreflight'])
        ->middleware(['can:logistics.view', 'throttle:10,1'])
        ->whereNumber('order')->name('logistics.xlwms.order.inline-preflight');
    Route::post('/logistics/oversea/us-3sm/orders/{order}/xlwms/send',
        [XlwmsSingleOrderController::class, 'send'])
        ->middleware(['can:logistics.operate', RequireLogisticsExecution::class, RejectXlwmsAutomaticCreate::class, 'throttle:2,1'])
        ->whereNumber('order')->name('logistics.xlwms.order.send');
    Route::post('/logistics/oversea/us-3sm/orders/{order}/xlwms/web-draft',
        [XlwmsSingleOrderController::class, 'webDraft'])
        ->middleware(['can:logistics.operate', RequireLogisticsExecution::class, RejectXlwmsAutomaticCreate::class, 'throttle:2,1'])
        ->whereNumber('order')->name('logistics.xlwms.order.web-draft');
    Route::get('/logistics/oversea/us-3sm/orders/{order}/xlwms/reship-preflight',
        [XlwmsReshipmentController::class, 'preflight'])
        ->middleware(['can:logistics.operate', RequireLogisticsExecution::class, 'throttle:8,1'])
        ->whereNumber('order')->name('logistics.xlwms.order.reship-preflight');
    Route::post('/logistics/oversea/us-3sm/orders/{order}/xlwms/reship-intent',
        [XlwmsReshipmentController::class, 'requestIntent'])
        ->middleware(['can:logistics.operate', RequireLogisticsExecution::class, 'throttle:2,1'])
        ->whereNumber('order')->name('logistics.xlwms.order.reship-intent');
    Route::post('/logistics/oversea/us-3sm/orders/{order}/xlwms/reship-approve',
        [XlwmsReshipmentController::class, 'approveIntent'])
        ->middleware(['can:logistics.operate', RequireLogisticsExecution::class, 'throttle:2,1'])
        ->whereNumber('order')->name('logistics.xlwms.order.reship-approve');
    Route::post('/logistics/oversea/us-3sm/orders/{order}/xlwms/reship-manual-bind/{requestId}',
        [XlwmsReshipmentController::class, 'bindManualDraft'])
        ->middleware(['can:logistics.operate', RequireLogisticsExecution::class, 'throttle:5,1'])
        ->whereNumber('order')->whereUuid('requestId')->name('logistics.xlwms.order.reship-manual-bind');
    Route::get('/logistics/oversea/us-3sm/orders/{order}/xlwms/reship-manual-draft/{requestId}',
        [XlwmsReshipmentController::class, 'manualDraftExport'])
        ->middleware(['can:logistics.operate', RequireLogisticsExecution::class, 'throttle:6,1'])
        ->whereNumber('order')->whereUuid('requestId')->name('logistics.xlwms.order.reship-manual-draft');
    Route::post('/logistics/oversea/us-3sm/orders/{order}/xlwms/reship-create',
        [XlwmsReshipmentController::class, 'createDraft'])
        ->middleware(['can:logistics.operate', RequireLogisticsExecution::class, RejectXlwmsAutomaticCreate::class, 'throttle:1,1'])
        ->whereNumber('order')->name('logistics.xlwms.order.reship-create');
    Route::get('/logistics/oversea/us-3sm/orders/{order}/xlwms/reship-status/{requestId}',
        [XlwmsReshipmentController::class, 'status'])
        ->middleware(['can:logistics.operate', RequireLogisticsExecution::class, 'throttle:5,1'])
        ->whereNumber('order')->whereUuid('requestId')->name('logistics.xlwms.order.reship-status');
    Route::post('/logistics/oversea/us-3sm/orders/{order}/xlwms/status',
        [XlwmsSingleOrderController::class, 'status'])
        ->middleware(['can:logistics.view', 'throttle:5,1'])
        ->whereNumber('order')->name('logistics.xlwms.order.status');
    Route::post('/logistics/oversea/us-3sm/orders/{order}/xlwms/reconcile',
        [XlwmsSingleOrderController::class, 'reconcile'])
        ->middleware(['can:logistics.operate', RequireLogisticsExecution::class, 'throttle:5,1'])
        ->whereNumber('order')->name('logistics.xlwms.order.reconcile');
    // MorePlus / ShipOut: sandbox-only preview/dispatch/readback, strictly
    // scoped to the existing US-LA overseas workbench and ERP order identity.
    Route::post('/logistics/oversea/us-moreplus/orders/{order}/shipout/label-stage',
        [ShipOutMorePlusController::class, 'stageLabel'])
        ->middleware(['can:logistics.operate', RequireLogisticsExecution::class, 'throttle:3,1'])
        ->whereNumber('order')->name('logistics.shipout.label-stage');
    // Mode 6 label tokens only travel in POST bodies, never GET URLs/logs.
    Route::post('/logistics/oversea/us-moreplus/orders/{order}/shipout/preflight',
        [ShipOutMorePlusController::class, 'preflight'])
        ->middleware(['can:logistics.operate', RequireLogisticsExecution::class, 'throttle:8,1'])
        ->whereNumber('order')->name('logistics.shipout.preflight.label');
    Route::get('/logistics/oversea/us-moreplus/orders/{order}/shipout/preflight',
        [ShipOutMorePlusController::class, 'preflight'])
        ->middleware(['can:logistics.view', 'throttle:10,1'])
        ->whereNumber('order')->name('logistics.shipout.preflight');
    Route::post('/logistics/oversea/us-moreplus/orders/{order}/shipout/send',
        [ShipOutMorePlusController::class, 'send'])
        ->middleware(['can:logistics.operate', RequireLogisticsExecution::class, 'throttle:2,1'])
        ->whereNumber('order')->name('logistics.shipout.send');
    Route::post('/logistics/oversea/us-moreplus/orders/{order}/shipout/status',
        [ShipOutMorePlusController::class, 'status'])
        ->middleware(['can:logistics.view', 'throttle:5,1'])
        ->whereNumber('order')->name('logistics.shipout.status');
    Route::post('/logistics/oversea/us-moreplus/orders/{order}/shipout/apply',
        [ShipOutMorePlusController::class, 'apply'])
        ->middleware(['can:logistics.operate', RequireLogisticsExecution::class, 'throttle:2,1'])
        ->whereNumber('order')->name('logistics.shipout.apply');
    // Production V1 uses existing, signed R10 transport and one-shot ledger.
    // Independent default-OFF flags; no sandbox endpoint or bulk/automatic retries.
    Route::get('/logistics/oversea/us-moreplus/orders/{order}/shipout/production/preflight',
        [ShipOutProductionV1Controller::class, 'preflight'])
        ->middleware(['can:logistics.view', 'throttle:8,1,shipout-prod-preflight:'])
        ->whereNumber('order')->name('logistics.shipout.production.preflight');
    Route::post('/logistics/oversea/us-moreplus/orders/{order}/shipout/production/send',
        [ShipOutProductionV1Controller::class, 'send'])
        ->middleware(['can:logistics.view', RequireShipOutProductionV1Operator::class, 'throttle:2,1,shipout-prod-send:'])
        ->whereNumber('order')->name('logistics.shipout.production.send');
    Route::post('/logistics/oversea/us-moreplus/orders/{order}/shipout/production/status',
        [ShipOutProductionV1Controller::class, 'status'])
        ->middleware(['can:logistics.view', 'throttle:5,1,shipout-prod-status:'])
        ->whereNumber('order')->name('logistics.shipout.production.status');
    Route::get('/logistics/oversea/us-moreplus/shipout/batch/preview',
        [ShipOutProductionBatchController::class, 'preview'])
        ->middleware(['can:logistics.view', 'throttle:8,1,shipout-batch-preview:'])
        ->name('logistics.shipout.production.batch.preview');
    Route::post('/logistics/oversea/us-moreplus/shipout/batch/start',
        [ShipOutProductionBatchController::class, 'start'])
        ->middleware(['can:logistics.view', 'throttle:2,1,shipout-batch-start:'])
        ->name('logistics.shipout.production.batch.start');
    Route::get('/logistics/oversea/us-moreplus/shipout/batch/{batchId}/status',
        [ShipOutProductionBatchController::class, 'status'])
        ->middleware(['can:logistics.view', 'throttle:30,1,shipout-batch-status:'])
        ->whereUuid('batchId')->name('logistics.shipout.production.batch.status');
    Route::get('/logistics/oversea/{warehouse}/export', [OverseaWarehouseController::class, 'export'])->middleware('can:logistics.view')->where('warehouse', 'us-ny|us-moreplus|us-3sm|us-sm|us-sj|usa-sj|de-tianyun|ca')->name('logistics.oversea.export');
    Route::post('/logistics/oversea/{warehouse}/tracking-import/preview', [OverseaWarehouseController::class, 'previewTrackingReturn'])->middleware($execution)->where('warehouse', 'us-ny|us-moreplus|us-3sm|us-sm|us-sj|usa-sj|de-tianyun|ca')->name('logistics.oversea.tracking-import.preview');
    Route::post('/logistics/oversea/{warehouse}/tracking-import/apply', [OverseaWarehouseController::class, 'applyTrackingReturn'])->middleware($execution)->where('warehouse', 'us-ny|us-moreplus|us-3sm|us-sm|us-sj|usa-sj|de-tianyun|ca')->name('logistics.oversea.tracking-import.apply');
    Route::patch('/logistics/oversea/orders/{order}/draft', [OverseaWarehouseController::class, 'updateDraft'])->middleware($review)->name('logistics.oversea.draft');
    Route::post('/logistics/oversea/orders/{order}/approve', [OverseaWarehouseController::class, 'approve'])->middleware($review)->name('logistics.oversea.approve');
    Route::post('/logistics/oversea/orders/{order}/reopen-review', [OverseaWarehouseController::class, 'reopenReview'])->middleware($execution)->name('logistics.oversea.reopen-review');
    Route::post('/logistics/oversea/orders/{order}/reject', [OverseaWarehouseController::class, 'reject'])->middleware($review)->name('logistics.oversea.reject');
    Route::get('/logistics/awaiting-tracking', [LogisticsController::class, 'awaitingTracking'])->middleware('can:logistics.view')->name('logistics.awaiting-tracking');
    Route::get('/logistics/in-transit', [LogisticsController::class, 'inTransit'])->middleware('can:logistics.view')->name('logistics.in-transit');
    Route::get('/logistics/exceptions', [LogisticsController::class, 'exceptions'])->middleware('can:logistics.view')->name('logistics.exceptions');
    Route::get('/logistics/review', [LogisticsController::class, 'review'])->middleware('can:logistics.view')->name('logistics.review');
    Route::get('/logistics/completed', [LogisticsController::class, 'completed'])->middleware('can:logistics.view')->name('logistics.completed');
    Route::get('/logistics/cancelled', [LogisticsController::class, 'cancelled'])->middleware('can:logistics.view')->name('logistics.cancelled');
    Route::get('/logistics/carriers', [CarrierController::class, 'index'])->middleware('can:logistics.view')->name('logistics.carriers');
    Route::post('/logistics/carriers', [CarrierController::class, 'store'])->middleware('can:logistics.operate')->name('logistics.carriers.store');
    Route::get('/logistics/carriers/{carrier}', [CarrierController::class, 'show'])->middleware('can:logistics.view')->name('logistics.carriers.show');
    Route::patch('/logistics/carriers/{carrier}', [CarrierController::class, 'update'])->middleware('can:logistics.operate')->name('logistics.carriers.update');
    Route::get('/logistics/historical-import', [HistoricalImportController::class, 'index'])->middleware('can:logistics.view')->name('logistics.historical-import');
    Route::post('/logistics/historical-import/preview', [HistoricalImportController::class, 'preview'])->middleware('can:logistics.operate')->name('logistics.historical-import.preview');
    Route::post('/logistics/historical-import/{batch}/commit', [HistoricalImportController::class, 'commit'])->middleware('can:logistics.operate')->name('logistics.historical-import.commit');
    Route::post('/logistics/historical-import/{batch}/review-rescue/commit', [HistoricalImportController::class, 'commitReviewRescue'])->middleware('can:logistics.operate')->name('logistics.historical-import.review-rescue.commit');
    Route::post('/logistics/historical-import/{batch}/rescue-attempts/preview', [HistoricalImportController::class, 'previewRescueAttempts'])->middleware('can:logistics.operate')->name('logistics.historical-import.rescue-attempts.preview');
    Route::post('/logistics/historical-import/{batch}/rescue-attempts/{projection}/apply', [HistoricalImportController::class, 'applyRescueAttempts'])->middleware('can:logistics.operate')->whereNumber('projection')->name('logistics.historical-import.rescue-attempts.apply');
    Route::post('/logistics/orders/{order}/draft', [LogisticsController::class, 'saveDraft'])->middleware($execution)->name('logistics.draft');
    Route::post('/logistics/orders/{order}/adopt-recommendation', [LogisticsController::class, 'adoptRecommendation'])->middleware($execution)->name('logistics.adopt-recommendation');
    Route::post('/logistics/wait-stock/batch-allocation', [LogisticsController::class, 'batchAllocate'])
        ->middleware([...$allocation, 'throttle:6,1'])->name('logistics.fulfillment-allocation.batch');
    Route::post('/logistics/orders/{order}/fulfillment-allocation/recommended', [LogisticsController::class, 'submitRecommendedAllocation'])->middleware($allocation)->name('logistics.fulfillment-allocation.recommended');
    Route::post('/logistics/orders/{order}/fulfillment-allocation/preflight', [LogisticsController::class, 'preflightManualAllocation'])->middleware($allocation)->name('logistics.fulfillment-allocation.preflight');
    Route::get('/logistics/orders/{order}/fulfillment-allocation/status', [LogisticsController::class, 'allocationSubmissionStatus'])->middleware($allocation)->name('logistics.fulfillment-allocation.status');
    Route::post('/logistics/orders/{order}/fulfillment-allocation/manual', [LogisticsController::class, 'submitManualAllocation'])->middleware($allocation)->name('logistics.fulfillment-allocation.manual');
    Route::post('/logistics/orders/{order}/fulfillment-allocation/quick', [LogisticsController::class, 'submitQuickAllocation'])->middleware($allocation)->name('logistics.fulfillment-allocation.quick');
    Route::post('/logistics/orders/{order}/reallocate-pending-fulfillment', [LogisticsController::class, 'reallocatePendingFulfillment'])->middleware($allocation)->name('logistics.fulfillment.reallocate-pending');
    Route::post('/logistics/orders/{order}/replacement-fulfillments', [LogisticsController::class, 'createReplacementFulfillment'])->middleware($execution)->name('logistics.replacement-fulfillments.store');
    Route::get('/logistics/orders/{order}/payload', [LogisticsController::class, 'payload'])->middleware('can:logistics.view')->name('logistics.payload');
    Route::post('/logistics/orders/{order}/ship', [LogisticsController::class, 'markShipped'])->middleware($execution)->name('logistics.ship');
    Route::post('/logistics/orders/{order}/reopen', [LogisticsController::class, 'reopen'])->middleware($allocation)->name('logistics.reopen');
    Route::post('/logistics/orders/{order}/update-tracking', [LogisticsController::class, 'updateTracking'])->middleware($execution)->name('logistics.update-tracking');
    Route::post('/logistics/orders/{order}/mark-exception', [LogisticsController::class, 'markException'])->middleware($execution)->name('logistics.mark-exception');
    Route::post('/logistics/orders/{order}/resolve-exception', [LogisticsController::class, 'resolveException'])->middleware($execution)->name('logistics.resolve-exception');
    Route::post('/logistics/orders/{order}/review', [LogisticsController::class, 'decideReview'])->middleware($execution)->name('logistics.review.decide');
    Route::post('/logistics/orders/{order}/pause', [LogisticsController::class, 'pause'])->middleware($execution)->name('logistics.pause');
    Route::post('/logistics/orders/{order}/redispatch', [LogisticsController::class, 'redispatch'])->middleware($execution)->name('logistics.redispatch');
    Route::post('/logistics/orders/{order}/reship', [LogisticsController::class, 'reship'])->middleware($execution)->name('logistics.reship');
    Route::post('/logistics/orders/{order}/contact-user', [LogisticsController::class, 'contactUser'])->middleware($execution)->name('logistics.contact-user');
    Route::post('/logistics/orders/{order}/cancel-exception', [LogisticsController::class, 'cancelException'])->middleware($execution)->name('logistics.cancel-exception');
    Route::post('/logistics/orders/{order}/mark-completed', [LogisticsController::class, 'markCompleted'])->middleware($execution)->name('logistics.mark-completed');
});
