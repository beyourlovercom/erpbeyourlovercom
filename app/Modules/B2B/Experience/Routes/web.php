<?php

use App\Modules\B2B\Experience\Controllers\B2BOverviewController;
use App\Modules\B2B\Experience\Controllers\B2BCompanyController;
use App\Modules\B2B\Experience\Controllers\B2BCaseController;
use App\Modules\B2B\Experience\Controllers\B2BCaseWriteController;
use App\Modules\B2B\Experience\Controllers\B2BSalesOpportunityController;
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

Route::middleware(['web', 'auth', 'can:b2b.view'])->group(function (): void {
    Route::get('/b2b/inquiries', [B2BCaseController::class, 'index'])->name('b2b.inquiries.index');
    Route::get('/b2b/cases/{case}', [B2BCaseController::class, 'show'])->whereNumber('case')->name('b2b.cases.show');
});

Route::middleware(['web', 'auth', 'can:b2b.view', 'can:b2b.operate'])->group(function (): void {
    Route::get('/b2b/inquiries/create', [B2BCaseWriteController::class, 'create'])->name('b2b.inquiries.create');
    Route::post('/b2b/inquiries', [B2BCaseWriteController::class, 'store'])->name('b2b.inquiries.store');
    Route::patch('/b2b/cases/{case}', [B2BCaseWriteController::class, 'update'])->whereNumber('case')->name('b2b.cases.update');
    Route::post('/b2b/cases/{case}/notes', [B2BCaseWriteController::class, 'addNote'])->whereNumber('case')->name('b2b.cases.notes.store');
});
Route::middleware(['web', 'auth', 'can:b2b.view', 'can:b2b.assign', 'can:b2b.view_all'])
    ->post('/b2b/cases/{case}/assignment', [B2BCaseWriteController::class, 'assign'])
    ->whereNumber('case')->name('b2b.cases.assignment');

Route::middleware(['web', 'auth', 'can:b2b.view'])
    ->get('/b2b/followups', [B2BCaseController::class, 'index'])
    ->name('b2b.followups');


// YBY Sales OS parity / S1. BYL B2B-only company/contact/case FKs; no legacy C-end Customers.
Route::middleware(['web', 'auth', 'can:b2b.view'])->group(function (): void {
    Route::get('/b2b/sales', [B2BSalesOpportunityController::class, 'index'])->name('b2b.sales.index');
    Route::get('/b2b/sales/opportunities/{opportunity}', [B2BSalesOpportunityController::class, 'show'])
        ->whereNumber('opportunity')->name('b2b.sales.show');
});
Route::middleware(['web', 'auth', 'can:b2b.view', 'can:b2b.operate'])->group(function (): void {
    Route::post('/b2b/sales/opportunities', [B2BSalesOpportunityController::class, 'store'])->name('b2b.sales.store');
    Route::patch('/b2b/sales/opportunities/{opportunity}/stage', [B2BSalesOpportunityController::class, 'stage'])
        ->whereNumber('opportunity')->name('b2b.sales.stage');
});
