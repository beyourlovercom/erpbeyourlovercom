<?php

use App\Modules\Kol\Experience\Controllers\KolController;
use App\Modules\Kol\Experience\Controllers\KolOverviewController;
use App\Modules\Kol\Experience\Controllers\SavedViewController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['web', 'auth', 'can:kol.view'])->group(function (): void {
    Route::get('/kol', KolOverviewController::class)->name('shell.kol');
    Route::get('/kol/overview', fn () => redirect()->route('shell.kol'))->name('kol.overview');

    Route::get('/kol/library', [KolController::class, 'index'])->name('kol.library');
    Route::get('/kol/library/create', [KolController::class, 'create'])->middleware('can:kol.operate')->name('kol.library.create');
    Route::post('/kol/library', [KolController::class, 'store'])->middleware('can:kol.operate')->name('kol.library.store');
    Route::get('/kol/library/{kol}', [KolController::class, 'show'])->name('kol.library.show');
    Route::get('/kol/library/{kol}/edit', [KolController::class, 'edit'])->middleware('can:kol.operate')->name('kol.library.edit');
    Route::patch('/kol/library/{kol}', [KolController::class, 'update'])->middleware('can:kol.operate')->name('kol.library.update');
    Route::post('/kol/library/{kol}/archive', [KolController::class, 'archive'])->middleware('can:kol.operate')->name('kol.library.archive');
    Route::post('/kol/library/{kol}/reactivate', [KolController::class, 'reactivate'])->middleware('can:kol.operate')->name('kol.library.reactivate');

    Route::middleware('can:kol.views.manage')->group(function (): void {
        Route::post('/kol/views', [SavedViewController::class, 'store'])->name('kol.views.store');
        Route::patch('/kol/views/{savedView}', [SavedViewController::class, 'update'])->name('kol.views.update');
        Route::delete('/kol/views/{savedView}', [SavedViewController::class, 'destroy'])->name('kol.views.destroy');
    });

    // Placeholder routes for M1.1+ menu items — real at nav level, no business engine yet
    Route::get('/kol/opportunities', fn () => Inertia::render('Kol/PlaceholderPage', ['title' => '合作机会', 'note' => 'M1.1 CRM 包交付']))->name('kol.opportunities');
    Route::get('/kol/collaborations', fn () => Inertia::render('Kol/PlaceholderPage', ['title' => '合作记录', 'note' => 'M1.2 合作包交付']))->name('kol.collaborations');
    Route::get('/kol/communications', fn () => Inertia::render('Kol/PlaceholderPage', ['title' => '沟通跟进', 'note' => 'M1.1 CRM 包交付']))->name('kol.communications');
    Route::get('/kol/samples', fn () => Inertia::render('Kol/PlaceholderPage', ['title' => '样品与发货', 'note' => 'M1.2 合作包交付']))->name('kol.samples');
    Route::get('/kol/content', fn () => Inertia::render('Kol/PlaceholderPage', ['title' => '内容与发布', 'note' => 'M1.3 内容包交付']))->name('kol.content');
    Route::get('/kol/commissions', fn () => Inertia::render('Kol/PlaceholderPage', ['title' => '佣金与结算', 'note' => 'M1.4 财务包交付']))->name('kol.commissions');
    Route::get('/kol/affiliate', fn () => Inertia::render('Kol/PlaceholderPage', ['title' => '优惠码与 Affiliate', 'note' => 'M1.4 财务包交付']))->name('kol.affiliate');
});
