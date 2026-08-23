<?php

use App\Modules\Customers\Experience\Controllers\CompanyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/customers/companies', [CompanyController::class, 'index'])->middleware('can:customers.view')->name('customers.companies');
    Route::get('/customers/companies/create', [CompanyController::class, 'create'])->middleware('can:customers.operate')->name('customers.companies.create');
    Route::post('/customers/companies', [CompanyController::class, 'store'])->middleware('can:customers.operate')->name('customers.companies.store');
    Route::get('/customers/companies/{company}', [CompanyController::class, 'show'])->middleware('can:customers.view')->name('customers.companies.show');
    Route::get('/customers/companies/{company}/edit', [CompanyController::class, 'edit'])->middleware('can:customers.operate')->name('customers.companies.edit');
    Route::match(['put', 'patch'], '/customers/companies/{company}', [CompanyController::class, 'update'])->middleware('can:customers.operate')->name('customers.companies.update');
    Route::post('/customers/companies/{company}/archive', [CompanyController::class, 'archive'])->middleware('can:customers.operate')->name('customers.companies.archive');
    Route::post('/customers/companies/{company}/restore', [CompanyController::class, 'restore'])->middleware('can:customers.operate')->name('customers.companies.restore');
});
