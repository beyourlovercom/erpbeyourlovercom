<?php

use App\Modules\Logistics\Experience\Controllers\OverseaManualShipmentController;
use App\Modules\Logistics\Experience\Controllers\OverseaNonOmsManualShipmentController;
use App\Modules\Logistics\Experience\Controllers\ShipOutMorePlusManualFulfillmentController;
use App\Modules\Logistics\Experience\Middleware\RequireLogisticsExecution;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::post('/logistics/oversea/orders/{order}/manual-ship', OverseaNonOmsManualShipmentController::class)
        ->middleware(['can:logistics.operate', RequireLogisticsExecution::class, 'throttle:10,1'])
        ->whereNumber('order')->name('logistics.oversea.manual-non-oms');
    Route::post('/logistics/oversea/us-3sm/orders/{order}/manual-ship', OverseaManualShipmentController::class)
        ->middleware(['can:logistics.operate', RequireLogisticsExecution::class])
        ->name('logistics.oversea.us-3sm.manual-ship');
    Route::post('/logistics/oversea/us-moreplus/orders/{order}/manual-ship', ShipOutMorePlusManualFulfillmentController::class)
        ->middleware(['can:logistics.operate', RequireLogisticsExecution::class, 'throttle:5,1'])
        ->name('logistics.oversea.us-moreplus.manual-ship');
});
