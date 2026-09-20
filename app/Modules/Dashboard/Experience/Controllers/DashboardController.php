<?php

namespace App\Modules\Dashboard\Experience\Controllers;

use App\Modules\Dashboard\Application\DashboardMetrics;
use App\Modules\Identity\Application\AuthorizationKeys;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController
{
    private const PERIODS = ['7d', '30d', '90d', 'custom'];

    public function __construct(private readonly DashboardMetrics $metrics) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $canViewProcurement = $user?->can(AuthorizationKeys::ProcurementView->value) ?? false;
        $period = in_array((string) $request->query('period', '7d'), self::PERIODS, true)
            ? (string) $request->query('period', '7d')
            : '7d';

        return Inertia::render('Dashboard/Index', [
            'visibility' => [
                'operations' => $user?->can(AuthorizationKeys::LogisticsView->value) ?? false,
                'inventory' => $user?->can(AuthorizationKeys::InventoryView->value) ?? false,
                'sync' => $user?->can(AuthorizationKeys::SyncView->value) ?? false,
                'finance' => $user?->can(AuthorizationKeys::AnalysisView->value) ?? false,
                'procurement' => $canViewProcurement,
            ],
            'period' => $period,
            'dateFrom' => $period === 'custom' ? $this->dateQuery($request, 'date_from') : null,
            'dateTo' => $period === 'custom' ? $this->dateQuery($request, 'date_to') : null,
            'procurementProgress' => $canViewProcurement ? $this->metrics->procurementProgress() : null,
        ]);
    }

    private function dateQuery(Request $request, string $key): ?string
    {
        $value = trim((string) $request->query($key, ''));

        if ($value === '' || preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value) !== 1) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }
}
