<?php

namespace App\Modules\Dashboard\Experience\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Dashboard/Index');
    }
}
