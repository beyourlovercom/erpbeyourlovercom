<?php

namespace App\Modules\Sync\Experience\Controllers;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Sync\Application\SyncOrchestrationService;
use Illuminate\Database\Eloquent\Model;
use Inertia\Inertia;
use Inertia\Response;

final class SyncWorkspaceController
{
    public function __construct(private readonly SyncOrchestrationService $orchestration) {}

    public function index(): Response
    {
        $runs = $this->orchestration->recentRuns(50);
        $byDomain = $runs->groupBy(fn (Model $run): string => $this->domain($run));

        return Inertia::render('Sync/Index', [
            'domains' => [
                $this->domainCard('orders', '同步订单', 'WooCommerce / Shopify / ERP', '/sync/orders', $byDomain->get('orders')),
                $this->domainCard('warehouses', '同步仓库', '统一管理仓库 Connector 与库存同步', '/sync/warehouses', $byDomain->get('warehouses')),
                $this->domainCard('kol', '同步网红', 'Instagram / X 平台资料同步', '/sync/kol', $byDomain->get('kol')),
                $this->domainCard('exports', '导出平台订单', '按平台和范围导出订单数据', '/sync/exports', $byDomain->get('exports')),
            ],
            'queueSummary' => $this->orchestration->queueSummary(),
            'manualRuns' => $runs->filter(fn (Model $run): bool => $run->initiated_by !== null)
                ->take(15)->map(fn (Model $run): array => $this->runData($run))->values()->all(),
            'automaticRuns' => $runs->filter(fn (Model $run): bool => $run->initiated_by === null)
                ->take(15)->map(fn (Model $run): array => $this->runData($run))->values()->all(),
        ]);
    }

    public function warehouses(): Response
    {
        $warehouses = Warehouse::query()->orderBy('priority')->orderBy('id')->get();

        return Inertia::render('Sync/Warehouses', [
            'warehouses' => $warehouses->map(fn (Warehouse $warehouse): array => [
                'id' => $warehouse->id,
                'code' => $warehouse->code,
                'name' => $warehouse->name,
                'country_code' => $warehouse->country_code,
                'is_active' => $warehouse->is_active,
                'connector_status' => 'not_configured',
                'last_sync_at' => null,
            ])->values()->all(),
        ]);
    }
    public function kol(): Response
    {
        return Inertia::render('Sync/Kol', [
            'platforms' => [
                ['key' => 'instagram', 'label' => 'Instagram', 'status' => 'not_configured', 'last_sync_at' => null],
                ['key' => 'x', 'label' => 'X', 'status' => 'not_configured', 'last_sync_at' => null],
            ],
        ]);
    }

    public function exports(\Illuminate\Http\Request $request): Response
    {
        $canCustomerExport = $request->user()->can('customers.export') && $request->user()->can('system.permissions.manage');

        $exports = [
            ['key' => 'all', 'label' => '全部 ERP 订单', 'href' => '/orders/export'],
            ['key' => 'procurement', 'label' => '外采订单', 'href' => '/orders/procurement/export'],
        ];

        if ($canCustomerExport) {
            $exports[] = ['key' => 'customers', 'label' => '客户订单', 'href' => '/customers/orders/export'];
        }

        return Inertia::render('Sync/Exports', ['exports' => $exports]);
    }

    private function domain(Model $run): string
    {
        $explicit = $run->context['domain'] ?? null;
        if (in_array($explicit, ['orders', 'warehouses', 'kol', 'exports'], true)) {
            return $explicit;
        }

        return in_array($run->provider_key, ['woocommerce', 'shopify', 'erp'], true) ? 'orders' : 'orders';
    }
    private function domainCard(string $key, string $title, string $subtitle, string $href, $runs): array
    {
        $latest = $runs?->first();

        return [
            'key' => $key,
            'title' => $title,
            'subtitle' => $subtitle,
            'href' => $href,
            'last_sync_at' => $latest?->completed_at?->toIso8601String() ?? $latest?->started_at?->toIso8601String(),
            'status' => $latest?->status?->value ?? 'not_started',
            'succeeded_items' => (int) ($latest?->succeeded_items ?? 0),
            'failed_items' => (int) ($latest?->failed_items ?? 0),
            'conflict_items' => (int) ($latest?->conflict_items ?? 0),
        ];
    }

    private function runData(Model $run): array
    {
        return [
            'id' => $run->id,
            'domain' => $this->domain($run),
            'run_type' => $run->run_type,
            'provider_key' => $run->provider_key,
            'status' => $run->status->value,
            'initiator' => $run->initiator?->name,
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'requested_items' => (int) $run->requested_items,
            'succeeded_items' => (int) $run->succeeded_items,
            'failed_items' => (int) $run->failed_items,
            'conflict_items' => (int) $run->conflict_items,
        ];
    }
}
