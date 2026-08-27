<?php

use App\Modules\Orders\Experience\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/orders', [OrderController::class, 'index'])->middleware('can:orders.view')->name('orders.index')->defaults('order_kind', 'all');
    Route::get('/orders/export', [OrderController::class, 'export'])->middleware('can:orders.view')->name('orders.export.all')->defaults('order_kind', 'all');
    Route::get('/customers/orders', [OrderController::class, 'index'])->middleware('can:orders.view')->name('customers.orders')->defaults('order_kind', 'customer');
    Route::get('/customers/orders/create', [OrderController::class, 'create'])->middleware('can:orders.create')->name('customers.orders.create')->defaults('order_kind', 'customer');
    Route::post('/customers/orders', [OrderController::class, 'store'])->middleware('can:orders.create')->name('orders.store.customers')->defaults('order_kind', 'customer');
    Route::get('/customers/orders/export', [OrderController::class, 'export'])->middleware('can:orders.view')->name('orders.export')->defaults('order_kind', 'customer');
    Route::get('/customers/orders/{order}', [OrderController::class, 'show'])->middleware('can:orders.view')->where('order', '[A-Z]{3}-[0-9]{8}-[A-Z0-9]{6}|[0-9]+')->name('customers.orders.show')->defaults('order_kind', 'customer');
    Route::patch('/customers/orders/{order}', [OrderController::class, 'update'])->middleware('can:orders.edit')->where('order', '[A-Z]{3}-[0-9]{8}-[A-Z0-9]{6}|[0-9]+')->name('orders.update.customers')->defaults('order_kind', 'customer');

    Route::get('/kol/orders', [OrderController::class, 'index'])->middleware('can:orders.view')->name('kol.orders')->defaults('order_kind', 'kol_fulfillment');
    Route::get('/kol/orders/create', [OrderController::class, 'create'])->middleware('can:orders.create')->name('kol.orders.create')->defaults('order_kind', 'kol_fulfillment');
    Route::post('/kol/orders', [OrderController::class, 'store'])->middleware('can:orders.create')->name('orders.store')->defaults('order_kind', 'kol_fulfillment');
    Route::get('/kol/orders/{order}', [OrderController::class, 'show'])->middleware('can:orders.view')->where('order', '[A-Z]{3}-[0-9]{8}-[A-Z0-9]{6}|KOL[0-9]{8}-[0-9]{3}|[0-9]+')->name('kol.orders.show')->defaults('order_kind', 'kol_fulfillment');
    Route::patch('/kol/orders/{order}', [OrderController::class, 'update'])->middleware('can:orders.edit')->name('orders.update')->defaults('order_kind', 'kol_fulfillment');
});
