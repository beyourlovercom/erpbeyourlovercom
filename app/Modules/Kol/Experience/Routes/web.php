<?php

use App\Modules\Kol\Experience\Controllers\KolController;
use App\Modules\Kol\Experience\Controllers\KolCommunicationController;
use App\Modules\Kol\Experience\Controllers\KolCollaborationController;
use App\Modules\Kol\Experience\Controllers\KolContentController;
use App\Modules\Kol\Experience\Controllers\KolOverviewController;
use App\Modules\Kol\Experience\Controllers\KolOpportunityController;
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
    Route::post('/kol/library/{kol}/avatar', [KolController::class, 'uploadAvatar'])->middleware('can:kol.operate')->name('kol.library.avatar');
    Route::post('/kol/library/{kol}/opportunities', [KolOpportunityController::class, 'store'])->middleware('can:kol.operate')->name('kol.library.opportunities.store');
    Route::patch('/kol/library/{kol}/opportunities/{opportunity}', [KolOpportunityController::class, 'update'])->middleware('can:kol.operate')->name('kol.library.opportunities.update');
    Route::post('/kol/library/{kol}/communications', [KolCommunicationController::class, 'store'])->middleware('can:kol.operate')->name('kol.library.communications.store');
    Route::patch('/kol/library/{kol}/communications/{communication}', [KolCommunicationController::class, 'update'])->middleware('can:kol.operate')->name('kol.library.communications.update');
    Route::post('/kol/library/{kol}/collaborations', [KolCollaborationController::class, 'store'])->middleware('can:kol.operate')->name('kol.library.collaborations.store');
    Route::patch('/kol/library/{kol}/collaborations/{collaboration}', [KolCollaborationController::class, 'update'])->middleware('can:kol.operate')->name('kol.library.collaborations.update');
    Route::post('/kol/library/{kol}/collaborations/{collaboration}/fulfillment-order', [KolCollaborationController::class, 'createFulfillmentOrder'])->middleware(['can:kol.operate', 'can:orders.create'])->name('kol.library.collaborations.fulfillment-order');
    Route::post('/kol/library/{kol}/opportunities/{opportunity}/convert-to-collaboration', [KolCollaborationController::class, 'convertOpportunity'])->middleware('can:kol.operate')->name('kol.library.opportunities.convert-collaboration');
    Route::post('/kol/library/{kol}/archive', [KolController::class, 'archive'])->middleware('can:kol.operate')->name('kol.library.archive');
    Route::post('/kol/library/{kol}/reactivate', [KolController::class, 'reactivate'])->middleware('can:kol.operate')->name('kol.library.reactivate');

    Route::middleware('can:kol.views.manage')->group(function (): void {
        Route::post('/kol/views', [SavedViewController::class, 'store'])->name('kol.views.store');
        Route::patch('/kol/views/{savedView}', [SavedViewController::class, 'update'])->name('kol.views.update');
        Route::delete('/kol/views/{savedView}', [SavedViewController::class, 'destroy'])->name('kol.views.destroy');
    });

    // Placeholder routes for M1.1+ menu items — real at nav level, no business engine yet
    Route::get('/kol/opportunities', fn () => Inertia::render('Kol/PlaceholderPage', ['title' => '合作机会', 'note' => 'M1.1 CRM 包交付']))->name('kol.opportunities');
    Route::get('/kol/collaborations', [KolCollaborationController::class, 'index'])->name('kol.collaborations');
    Route::get('/kol/communications', fn () => Inertia::render('Kol/PlaceholderPage', ['title' => '沟通跟进', 'note' => 'M1.1 CRM 包交付']))->name('kol.communications');
    Route::get('/kol/samples', fn () => Inertia::render('Kol/PlaceholderPage', ['title' => '样品与发货', 'note' => 'M1.2 合作包交付']))->name('kol.samples');
    Route::get('/kol/content', [KolContentController::class, 'index'])->name('kol.content');
    Route::get('/kol/content/options', [KolContentController::class, 'options'])->name('kol.content.options');
    Route::post('/kol/contents', [KolContentController::class, 'store'])->middleware('can:kol.operate')->name('kol.contents.store');
    Route::patch('/kol/contents/{content}', [KolContentController::class, 'update'])->middleware('can:kol.operate')->name('kol.contents.update');
    Route::post('/kol/contents/{content}/evidence', [KolContentController::class, 'addEvidence'])->middleware('can:kol.operate')->name('kol.contents.evidence.store');
    Route::post('/kol/contents/{content}/sync-post-image', [KolContentController::class, 'syncPostImage'])->middleware('can:kol.operate')->name('kol.contents.sync-post-image');
    Route::get('/kol/contents/{content}/evidence/{attachment}', [KolContentController::class, 'showEvidence'])->name('kol.contents.evidence.show');
    Route::delete('/kol/contents/{content}/evidence/{attachment}', [KolContentController::class, 'deleteEvidence'])->middleware('can:kol.operate')->name('kol.contents.evidence.destroy');
    Route::get('/kol/commissions', fn () => Inertia::render('Kol/PlaceholderPage', ['title' => '佣金与结算', 'note' => 'M1.4 财务包交付']))->name('kol.commissions');
    Route::get('/kol/affiliate', fn () => Inertia::render('Kol/PlaceholderPage', ['title' => '优惠码与 Affiliate', 'note' => 'M1.4 财务包交付']))->name('kol.affiliate');
});
