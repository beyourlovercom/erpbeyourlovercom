<?php

use App\Modules\Products\Experience\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:products.view'])->group(function (): void {
    Route::get('/products', [ProductController::class, 'dashboard'])->name('products.dashboard');
    Route::get('/products/list', [ProductController::class, 'index'])->name('products.list');
    Route::get('/products/sku-search', [ProductController::class, 'skuSearch'])->name('products.sku-search');
});
