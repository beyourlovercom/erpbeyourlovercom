<?php

use App\Modules\Inventory\Experience\Controllers\InventoryController;
use App\Modules\Inventory\Experience\Controllers\InventorySyncController;
use App\Modules\Inventory\Experience\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web','auth','can:inventory.view'])->group(function (): void {
    Route::get('/inventory',[InventoryController::class,'dashboard'])->name('shell.inventory');
    Route::get('/inventory/list',[InventoryController::class,'warehouses'])->name('inventory.list');
    Route::get('/inventory/products',[InventoryController::class,'products'])->name('inventory.products');
    Route::get('/inventory/product-search',[InventoryController::class,'productSearch'])->name('inventory.product-search');
    Route::get('/inventory/warehouses',fn()=>redirect()->route('inventory.list'))->name('inventory.warehouses');
    Route::get('/inventory/{warehouse}',[WarehouseController::class,'show'])->where('warehouse','^(?!add$|list$|products$|product-search$|warehouses$|sync$)[^/]+$')->name('inventory.warehouse.show');
});

Route::middleware(['web','auth','can:inventory.warehouses.manage'])->group(function (): void {
    Route::get('/inventory/add',[WarehouseController::class,'create'])->name('inventory.add');
    Route::post('/inventory',[WarehouseController::class,'store'])->name('inventory.store');
    Route::patch('/inventory/{warehouse}',[WarehouseController::class,'update'])->name('inventory.warehouse.update');
    Route::patch('/inventory/{warehouse}/billing',[WarehouseController::class,'billing'])->name('inventory.warehouse.billing');
    Route::patch('/inventory/{warehouse}/regions',[WarehouseController::class,'regions'])->name('inventory.warehouse.regions');
});

Route::middleware(['web','auth','can:inventory.operate'])->group(function (): void {
    Route::post('/inventory/{warehouse}/stock',[WarehouseController::class,'saveInventory'])->name('inventory.stock.store');
    Route::patch('/inventory/{warehouse}/stock/{inventory}',[WarehouseController::class,'updateInventory'])->name('inventory.stock.update');
    Route::get('/inventory/sync',[InventorySyncController::class,'index'])->name('inventory.sync');
    Route::post('/inventory/sync/preview',[InventorySyncController::class,'preview'])->name('inventory.sync.preview');
    Route::post('/inventory/sync/{batch}/confirm',[InventorySyncController::class,'confirm'])->whereNumber('batch')->name('inventory.sync.confirm');
    Route::get('/inventory/sync/history',[InventorySyncController::class,'history'])->name('inventory.sync.history');
    Route::get('/inventory/sync/{batch}',[InventorySyncController::class,'show'])->whereNumber('batch')->name('inventory.sync.show');
});
