<?php

namespace App\Modules\Dashboard\Experience\Controllers;

use App\Modules\Dashboard\Application\DashboardMetrics;
use App\Modules\Identity\Application\AuthorizationKeys;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController
{
    public function __construct(private readonly DashboardMetrics $metrics) {}

    public function __invoke(Request $request): Response
    {
        $canViewProcurement = $request->user()?->can(AuthorizationKeys::ProcurementView->value) ?? false;
        return Inertia::render('Dashboard/Index', [
            'metrics' => $this->metrics->current(),
            'procurementProgress' => $canViewProcurement ? $this->metrics->procurementProgress() : null,
        ]);
    }
}
