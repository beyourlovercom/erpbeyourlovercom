<?php

namespace App\Modules\Dashboard\Experience\Controllers;

use App\Modules\Dashboard\Application\DashboardMetrics;
use App\Modules\Identity\Application\AuthorizationKeys;
use App\Modules\Identity\Application\RolePreset;
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

        $workspace = $this->workspace($user);

        return Inertia::render('Dashboard/Index', [
            'workspace' => $workspace,
            'quickActions' => $this->quickActions($workspace['role'], $user),
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

    private function workspace($user): array
    {
        $roleKey = $user?->roles()->pluck('key')->first();
        $preset = RolePreset::fromRoleKey($roleKey);
        $key = $preset?->value ?? 'custom';

        $meta = match ($preset) {
            RolePreset::Administrator => ['mode' => 'boss', 'eyebrow' => '老板 / 管理员总览', 'title' => '经营与运营总览', 'description' => '先看经营结果、异常和瓶颈，再进入对应业务系统处理。'],
            RolePreset::Operations => ['mode' => 'work', 'eyebrow' => '运营工作台', 'title' => '今日运营总览', 'description' => '聚合今天需要推进的履约、库存、同步与经营信号。'],
            RolePreset::Logistics => ['mode' => 'work', 'eyebrow' => '物流工作台', 'title' => '今日履约任务', 'description' => '优先处理待分仓、待发货、运输异常与复核任务。'],
            RolePreset::Finance => ['mode' => 'work', 'eyebrow' => '财务工作台', 'title' => '经营财务总览', 'description' => '聚焦收入、成本、利润覆盖与需要补齐的财务证据。'],
            RolePreset::KolSpecialist, RolePreset::KolManager => ['mode' => 'work', 'eyebrow' => '网红工作台', 'title' => '今日网红运营', 'description' => '聚合网红履约、审批、订单与待处理事项。'],
            RolePreset::SalesRepresentative => ['mode' => 'work', 'eyebrow' => '业务员工作台', 'title' => '今日客户与订单', 'description' => '聚合客户、订单和需要继续跟进的业务事项。'],
            RolePreset::Writer => ['mode' => 'work', 'eyebrow' => '内容工作台', 'title' => '今日内容任务', 'description' => '聚合内容与文档工作入口；业务数据仅按授权展示。'],
            default => ['mode' => 'work', 'eyebrow' => '我的工作台', 'title' => '今日工作总览', 'description' => '仅展示当前账号已授权的业务模块和入口。'],
        };

        return ['role' => $key, 'label' => $preset?->label() ?? '自定义权限'] + $meta;
    }

    private function quickActions(string $role, $user): array
    {
        $actions = match ($role) {
            'operations' => [['label'=>'待分仓','href'=>'/logistics/wait-stock','permission'=>'logistics.view'],['label'=>'待发货','href'=>'/logistics/pending','permission'=>'logistics.view'],['label'=>'网站同步','href'=>'/sync','permission'=>'sync.view'],['label'=>'库存预警','href'=>'/inventory/products?stock_status=alert','permission'=>'inventory.view']],
            'logistics' => [['label'=>'待分仓','href'=>'/logistics/wait-stock','permission'=>'logistics.view'],['label'=>'国内发货','href'=>'/logistics/domestic','permission'=>'logistics.view'],['label'=>'海外仓发货','href'=>'/logistics/overseas','permission'=>'logistics.view'],['label'=>'异常订单','href'=>'/logistics/exceptions','permission'=>'logistics.view']],
            'finance' => [['label'=>'订单利润','href'=>'/accounting/order-costs','permission'=>'analysis.view'],['label'=>'财务中心','href'=>'/accounting','permission'=>'accounting.view']],
            'kol_specialist' => [['label'=>'网红总览','href'=>'/kol','permission'=>'kol.view'],['label'=>'我的网红','href'=>'/kol/library','permission'=>'kol.view'],['label'=>'合作记录','href'=>'/kol/collaborations','permission'=>'kol.view'],['label'=>'内容记录','href'=>'/kol/content','permission'=>'kol.view']],
            'kol_manager' => [['label'=>'网红总览','href'=>'/kol','permission'=>'kol.view'],['label'=>'审批中心','href'=>'/kol/approvals','permission'=>'kol.approvals.manage'],['label'=>'合作记录','href'=>'/kol/collaborations','permission'=>'kol.view'],['label'=>'佣金','href'=>'/kol/commissions','permission'=>'kol.view']],
            'sales_representative' => [['label'=>'客户中心','href'=>'/customers','permission'=>'customers.view'],['label'=>'客户库','href'=>'/customers/library','permission'=>'customers.view'],['label'=>'订单','href'=>'/orders','permission'=>'orders.view']],
            'writer' => [['label'=>'内容文档','href'=>'/docs','permission'=>'guides.view']],
            default => [],
        };

        return array_values(array_map(
            fn (array $action): array => ['label'=>$action['label'],'href'=>$action['href']],
            array_filter($actions, fn (array $action): bool => $user?->can($action['permission']) ?? false),
        ));
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
