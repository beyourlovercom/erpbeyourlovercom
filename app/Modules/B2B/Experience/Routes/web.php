<?php

use App\Modules\B2B\Experience\Controllers\B2BOverviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'can:b2b.view'])
    ->get('/b2b', B2BOverviewController::class)
    ->name('b2b.overview');
