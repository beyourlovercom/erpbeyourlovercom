<?php

namespace App\Modules\Dashboard\Experience\Controllers;

use App\Modules\Dashboard\Application\DashboardMetrics;
use App\Modules\Identity\Application\AuthorizationKeys;
use Carbon\CarbonImmutable;
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
        $visibility = [
            'operations' => $user?->can(AuthorizationKeys::LogisticsView->value) ?? false,
            'inventory' => $user?->can(AuthorizationKeys::InventoryView->value) ?? false,
            'sync' => $user?->can(AuthorizationKeys::SyncView->value) ?? false,
            'finance' => $user?->can(AuthorizationKeys::AnalysisView->value) ?? false,
            'procurement' => $user?->can(AuthorizationKeys::ProcurementView->value) ?? false,
        ];

        $period = in_array((string) $request->query('period', '7d'), self::PERIODS, true)
            ? (string) $request->query('period', '7d')
            : '7d';
        [$period, $dateFrom, $dateTo] = $this->periodRange($request, $period);

        return Inertia::render('Dashboard/Index', [
            'visibility' => $visibility,
            'period' => $period,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'operations' => $visibility['operations'] ? $this->metrics->operations() : null,
            'inventoryRisk' => $visibility['inventory'] ? $this->metrics->inventoryRisk() : null,
            'syncHealth' => $visibility['sync'] ? $this->metrics->syncHealth() : null,
            'financeSnapshot' => $visibility['finance']
                ? Inertia::defer(fn (): array => $this->metrics->financeSnapshot($dateFrom, $dateTo), 'dashboard-finance')
                : null,
            'procurementProgress' => $visibility['procurement'] ? $this->metrics->procurementProgress() : null,
        ]);
    }

    private function periodRange(Request $request, string $period): array
    {
        $today = CarbonImmutable::today();

        if ($period === 'custom') {
            $from = $this->dateQuery($request, 'date_from');
            $to = $this->dateQuery($request, 'date_to');
            if ($from !== null && $to !== null && $from <= $to) {
                $toDate = CarbonImmutable::parse($to);
                $fromDate = CarbonImmutable::parse($from);
                if ($fromDate->diffInDays($toDate) > 365) {
                    $from = $toDate->subDays(365)->toDateString();
                }
                return [$period, $from, $to];
            }
            $period = '7d';
        }

        $days = match ($period) {
            '30d' => 30,
            '90d' => 90,
            default => 7,
        };

        return [$period, $today->subDays($days - 1)->toDateString(), $today->toDateString()];
    }

    private function dateQuery(Request $request, string $key): ?string
    {
        $value = trim((string) $request->query($key, ''));

        if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }
}
