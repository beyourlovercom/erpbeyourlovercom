<?php

use App\Modules\B2B\Experience\Controllers\B2BOverviewController;
use App\Modules\B2B\Experience\Controllers\B2BCompanyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:b2b.view'])
    ->get('/b2b', B2BOverviewController::class)
    ->name('b2b.overview');

Route::middleware(['web', 'auth', 'can:b2b.view'])->group(function (): void {
    Route::get('/b2b/companies', [B2BCompanyController::class, 'index'])->name('b2b.companies.index');
    Route::get('/b2b/companies/{organization}', [B2BCompanyController::class, 'show'])->whereNumber('organization')->name('b2b.companies.show');
});

Route::middleware(['web', 'auth', 'can:b2b.view', 'can:b2b.operate'])->group(function (): void {
    Route::get('/b2b/companies/create', [B2BCompanyController::class, 'create'])->name('b2b.companies.create');
    Route::post('/b2b/companies', [B2BCompanyController::class, 'store'])->name('b2b.companies.store');
    Route::post('/b2b/companies/{organization}/contacts', [B2BCompanyController::class, 'storeContact'])->whereNumber('organization')->name('b2b.companies.contacts.store');
});
