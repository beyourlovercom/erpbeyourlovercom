<?php

use App\Modules\Products\Experience\Controllers\ProductController;
use App\Modules\Products\Experience\Controllers\ProductSkuController;
use App\Modules\Products\Experience\Controllers\ProductSkuAliasController;
use App\Modules\Products\Experience\Controllers\ProductWeightEvidenceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:products.view'])->group(function (): void {
    Route::get('/products', [ProductController::class, 'dashboard'])->name('products.dashboard');
    Route::get('/products/list', [ProductController::class, 'index'])->name('products.list');
    Route::get('/products/sku-search', [ProductController::class, 'skuSearch'])->name('products.sku-search');
    Route::get('/products/skus', [ProductSkuController::class, 'index'])->name('products.skus');
    Route::get('/products/sku-aliases', [ProductSkuAliasController::class, 'index'])->name('products.sku-aliases');
    Route::get('/products/weight-evidence', [ProductWeightEvidenceController::class, 'index'])->name('products.weight-evidence.index');
    Route::get('/products/{id}', [ProductController::class, 'show'])->whereNumber('id')->name('products.show');
});

Route::middleware(['web', 'auth', 'can:products.operate'])->group(function (): void {
    // Weight evidence mutations
    Route::post('/products/weight-evidence', [ProductWeightEvidenceController::class, 'store'])->name('products.weight-evidence.store');
    Route::post('/products/weight-evidence/{id}/adopt', [ProductWeightEvidenceController::class, 'adopt'])->name('products.weight-evidence.adopt');
    Route::post('/products/weight-evidence/{id}/reject', [ProductWeightEvidenceController::class, 'reject'])->name('products.weight-evidence.reject');
    Route::post('/products/{productId}/clear-weight', [ProductWeightEvidenceController::class, 'clearWeight'])->name('products.weight.clear');
    Route::post('/products/weight-evidence/import/preview', [ProductWeightEvidenceController::class, 'importPreview'])->name('products.weight-evidence.import.preview');
    Route::post('/products/weight-evidence/import/confirm', [ProductWeightEvidenceController::class, 'importConfirm'])->name('products.weight-evidence.import.confirm');

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
