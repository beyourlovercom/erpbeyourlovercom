<?php

use App\Modules\Orders\Experience\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/customers/orders', [OrderController::class, 'index'])->middleware('can:orders.view')->name('customers.orders');
    Route::get('/customers/orders/create', [OrderController::class, 'create'])->middleware('can:orders.create')->name('customers.orders.create');
    Route::post('/customers/orders', [OrderController::class, 'store'])->middleware('can:orders.create')->name('orders.store.customers');
    Route::get('/customers/orders/export', [OrderController::class, 'export'])->middleware('can:orders.view')->name('orders.export');
    Route::get('/customers/orders/{order}', [OrderController::class, 'show'])->middleware('can:orders.view')->where('order', '[A-Z]{3}-[0-9]{8}-[A-Z0-9]{6}|[0-9]+')->name('customers.orders.show');
    Route::patch('/customers/orders/{order}', [OrderController::class, 'update'])->middleware('can:orders.edit')->where('order', '[A-Z]{3}-[0-9]{8}-[A-Z0-9]{6}|[0-9]+')->name('orders.update.customers');
    Route::get('/kol/orders', [OrderController::class, 'index'])->middleware('can:prototype.view')->name('kol.orders');
    Route::get('/kol/orders/create', [OrderController::class, 'create'])->middleware('can:prototype.view')->name('kol.orders.create');
    Route::post('/kol/orders', [OrderController::class, 'store'])->middleware('can:prototype.view')->name('orders.store');
    Route::get('/kol/orders/{order}', [OrderController::class, 'show'])->middleware('can:prototype.view')->where('order', '[A-Z]{3}-[0-9]{8}-[A-Z0-9]{6}|KOL[0-9]{8}-[0-9]{3}|[0-9]+')->name('kol.orders.show');
    Route::patch('/kol/orders/{order}', [OrderController::class, 'update'])->middleware('can:prototype.view')->name('orders.update');
});
