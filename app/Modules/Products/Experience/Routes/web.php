<?php

use App\Modules\Products\Experience\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:products.view'])->group(function (): void {
    Route::get('/products', [ProductController::class, 'dashboard'])->name('products.dashboard');
    Route::get('/products/list', [ProductController::class, 'index'])->name('products.list');
    Route::get('/products/sku-search', [ProductController::class, 'skuSearch'])->name('products.sku-search');
});

Route::middleware(['web', 'auth', 'can:products.operate'])->group(function (): void {
    Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::get('/products/import', [ProductController::class, 'importPage'])->name('products.import');
    Route::post('/products/import', [ProductController::class, 'importStore'])->name('products.import.store');
    Route::get('/products/{id}/edit', [ProductController::class, 'edit'])->name('products.edit');
    Route::patch('/products/{id}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('/products/{id}', [ProductController::class, 'destroy'])->name('products.destroy');
});
