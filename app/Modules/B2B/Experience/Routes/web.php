<?php

use App\Modules\B2B\Experience\Controllers\B2BOverviewController;
use App\Modules\B2B\Experience\Controllers\B2BCompanyController;
use App\Modules\B2B\Experience\Controllers\B2BCaseController;
use App\Modules\B2B\Experience\Controllers\B2BCaseWriteController;
use App\Modules\B2B\Experience\Controllers\B2BSalesOpportunityController;
use App\Modules\B2B\Experience\Controllers\B2BTradeOrderController;
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


// YBY Sales OS S2 — B2B-only follow-up, versioned draft quote, close/reopen and private files.
Route::middleware(['web','auth','can:b2b.view'])->get(
    '/b2b/sales/opportunities/{opportunity}/followups/{followUp}/attachments/{attachment}',
    [B2BSalesOpportunityController::class,'attachment']
)->whereNumber('opportunity')->whereNumber('followUp')->whereNumber('attachment')
    ->name('b2b.sales.followups.attachments.download');

Route::middleware(['web','auth','can:b2b.view','can:b2b.operate'])->group(function (): void {
    Route::post('/b2b/sales/opportunities/{opportunity}/followups', [B2BSalesOpportunityController::class,'followUp'])->whereNumber('opportunity')->name('b2b.sales.followups.store');
    Route::post('/b2b/sales/opportunities/{opportunity}/won', [B2BSalesOpportunityController::class,'won'])->whereNumber('opportunity')->name('b2b.sales.won');
    Route::post('/b2b/sales/opportunities/{opportunity}/lost', [B2BSalesOpportunityController::class,'lost'])->whereNumber('opportunity')->name('b2b.sales.lost');
    Route::post('/b2b/sales/opportunities/{opportunity}/reopen', [B2BSalesOpportunityController::class,'reopen'])->whereNumber('opportunity')->name('b2b.sales.reopen');
});
Route::middleware(['web','auth','can:b2b.view','can:b2b.operate','can:b2b.quotes_manage'])->post(
    '/b2b/sales/opportunities/{opportunity}/quotes',
    [B2BSalesOpportunityController::class,'quote']
)->whereNumber('opportunity')->name('b2b.sales.quotes.store');


// S3-G3 independent B2B Trade Order, separate from Woo customer Orders and Accounting.
Route::middleware(['web','auth','can:b2b.view'])->group(function (): void {
    Route::get('/b2b/trade-orders', [B2BTradeOrderController::class, 'index'])->name('b2b.trade.index');
    Route::get('/b2b/trade-orders/{tradeOrder}', [B2BTradeOrderController::class, 'show'])
        ->whereNumber('tradeOrder')->name('b2b.trade.show');
});
Route::middleware(['web','auth','can:b2b.view'])
    ->get('/b2b/trade-orders/{tradeOrder}/evidence/{evidence}/download', [B2BTradeOrderController::class, 'download'])
    ->whereNumber('tradeOrder')->whereNumber('evidence')->name('b2b.trade.evidence.download');
Route::middleware(['web','auth','can:b2b.view','can:b2b.operate'])->group(function (): void {
    Route::post('/b2b/trade-orders', [B2BTradeOrderController::class, 'store'])->name('b2b.trade.store');
    Route::post('/b2b/trade-orders/{tradeOrder}/evidence', [B2BTradeOrderController::class, 'evidence'])
        ->whereNumber('tradeOrder')->name('b2b.trade.evidence.store');
});
Route::middleware(['web','auth','can:b2b.view','can:b2b.view_all','can:b2b.trade.approve'])
    ->post('/b2b/trade-orders/{tradeOrder}/approve', [B2BTradeOrderController::class, 'approve'])
    ->whereNumber('tradeOrder')->name('b2b.trade.approve');
Route::middleware(['web','auth','can:b2b.view','can:b2b.view_all','can:b2b.receipts.review'])
    ->post('/b2b/trade-orders/{tradeOrder}/evidence/{evidence}/review', [B2BTradeOrderController::class, 'review'])
    ->whereNumber('tradeOrder')->whereNumber('evidence')->name('b2b.trade.evidence.review');
