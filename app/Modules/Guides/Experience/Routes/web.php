<?php

use App\Modules\Guides\Experience\Controllers\GuideController;
use App\Modules\Guides\Experience\Controllers\ReleaseNoteController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:guides.manage'])->group(function (): void {
    Route::get('/guides/create', [GuideController::class, 'create'])->name('guides.create');
    Route::get('/guides/changelog/create', [ReleaseNoteController::class, 'create'])->name('guides.releases.create');
});

Route::middleware(['web', 'auth', 'can:guides.view'])->group(function (): void {
    Route::get('/guides', [GuideController::class, 'index'])->name('shell.guides');
    Route::get('/guides/videos', [GuideController::class, 'videos'])->name('guides.videos');
    Route::get('/guides/manuals', [GuideController::class, 'manuals'])->name('guides.manuals');
    Route::get('/guides/changelog', [ReleaseNoteController::class, 'index'])->name('guides.releases.index');
    Route::get('/guides/changelog/{releaseNote}', [ReleaseNoteController::class, 'show'])->whereNumber('releaseNote')->name('guides.releases.show');
    Route::get('/guides/{guide}', [GuideController::class, 'show'])->name('guides.show');
});

Route::middleware(['web', 'auth', 'can:guides.manage'])->group(function (): void {
    Route::post('/guides', [GuideController::class, 'store'])->name('guides.store');
    Route::get('/guides/{guide}/edit', [GuideController::class, 'edit'])->name('guides.edit');
    Route::patch('/guides/{guide}', [GuideController::class, 'update'])->name('guides.update');
    Route::post('/guides/{guide}/publish', [GuideController::class, 'publish'])->name('guides.publish');
    Route::post('/guides/{guide}/unpublish', [GuideController::class, 'unpublish'])->name('guides.unpublish');

    Route::post('/guides/changelog', [ReleaseNoteController::class, 'store'])->name('guides.releases.store');
    Route::get('/guides/changelog/{releaseNote}/edit', [ReleaseNoteController::class, 'edit'])->whereNumber('releaseNote')->name('guides.releases.edit');
    Route::patch('/guides/changelog/{releaseNote}', [ReleaseNoteController::class, 'update'])->whereNumber('releaseNote')->name('guides.releases.update');
    Route::post('/guides/changelog/{releaseNote}/publish', [ReleaseNoteController::class, 'publish'])->whereNumber('releaseNote')->name('guides.releases.publish');
    Route::post('/guides/changelog/{releaseNote}/unpublish', [ReleaseNoteController::class, 'unpublish'])->whereNumber('releaseNote')->name('guides.releases.unpublish');
});
