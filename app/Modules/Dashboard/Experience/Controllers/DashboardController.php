<?php

namespace App\Modules\Dashboard\Experience\Controllers;

use App\Modules\Dashboard\Application\DashboardDataContract;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController
{
    public function __construct(private readonly DashboardDataContract $data) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard/Index', $this->data->current($user));
    }
}
