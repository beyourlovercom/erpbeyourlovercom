<?php

use App\Modules\Products\Experience\Controllers\ProductController;
use App\Modules\Products\Experience\Controllers\ProductSkuController;
use App\Modules\Products\Experience\Controllers\ProductSkuAliasController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:products.view'])->group(function (): void {
    Route::get('/products', [ProductController::class, 'dashboard'])->name('products.dashboard');
    Route::get('/products/list', [ProductController::class, 'index'])->name('products.list');
    Route::get('/products/sku-search', [ProductController::class, 'skuSearch'])->name('products.sku-search');
    Route::get('/products/skus', [ProductSkuController::class, 'index'])->name('products.skus');
    Route::get('/products/sku-aliases', [ProductSkuAliasController::class, 'index'])->name('products.sku-aliases');
});

Route::middleware(['web', 'auth', 'can:products.operate'])->group(function (): void {
    Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::get('/products/import', [ProductController::class, 'importPage'])->name('products.import');
    Route::get('/products/import/template', [ProductController::class, 'importTemplate'])->name('products.import.template');
    Route::post('/products/import', [ProductController::class, 'importStore'])->name('products.import.store');
    Route::get('/products/{id}/edit', [ProductController::class, 'edit'])->name('products.edit');
    Route::patch('/products/{id}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('/products/{id}', [ProductController::class, 'destroy'])->name('products.destroy');

    // SKU Alias mutations
    Route::post('/products/sku-aliases/aliases', [ProductSkuAliasController::class, 'storeAlias'])->name('products.sku-aliases.store');
    Route::delete('/products/sku-aliases/aliases/{id}', [ProductSkuAliasController::class, 'destroyAlias'])->name('products.sku-aliases.destroy');

    // Reconciliation mutations
    Route::post('/products/sku-aliases/reconciliations/{id}/resolve', [ProductSkuAliasController::class, 'resolveReconciliation'])->name('products.reconciliations.resolve');
    Route::post('/products/sku-aliases/reconciliations/{id}/ignore', [ProductSkuAliasController::class, 'ignoreReconciliation'])->name('products.reconciliations.ignore');
    Route::post('/products/sku-aliases/reconciliations/{id}/reopen', [ProductSkuAliasController::class, 'reopenReconciliation'])->name('products.reconciliations.reopen');
});
