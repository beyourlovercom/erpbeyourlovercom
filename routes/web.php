<?php

use App\Http\Middleware\HandleInertiaRequests;
use Inertia\Inertia;
use Illuminate\Support\Facades\Route;

Route::get('/architecture/foundation', fn () => Inertia::render('Architecture/Foundation', [
    'title' => 'BYL ERP Foundation',
    'status' => 'ARCHITECTURE VALIDATION',
]))->middleware([HandleInertiaRequests::class, 'auth', 'can:architecture.foundation.view'])->name('architecture.foundation');

// OMS Grid R0–R2: authenticated non-Production visual and interaction UAT only.
if (in_array(app()->environment(), ['local', 'staging', 'development', 'testing'], true)) {
    Route::get('/_uat/oms-grid', fn () => Inertia::render('Architecture/OmsGridPreview'))
        ->middleware(['auth'])->name('architecture.oms-grid-uat');
}
