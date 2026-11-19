<?php

namespace App\Modules\Products\Experience\Controllers;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Finance\Application\FxReferenceSettingsService;
use App\Modules\Identity\Application\RolePreset;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Products\Application\ConfirmedProductCostSourceImportService;
use App\Modules\Products\Application\MonthlyInventoryCostResolutionPlanner;
use App\Modules\Products\Application\MonthlyInventoryCostWorkbookReader;
use App\Modules\Products\Infrastructure\Models\ProductCostSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class MonthlyInventoryCostImportController
{
    public function __construct(
        private readonly MonthlyInventoryCostWorkbookReader $reader,
        private readonly MonthlyInventoryCostResolutionPlanner $planner,
        private readonly ConfirmedProductCostSourceImportService $confirmed,
        private readonly FxReferenceSettingsService $fx,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Products/MonthlyCostImport', [
            'applyEnabled' => $this->canApply($request),
            'applyMode' => app()->environment('production') ? 'production' : 'dev',
        ]);
    }

    public function catalog(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'month' => ['nullable', 'date_format:Y-m'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));
        $month = $filters['month'] ?? null;
        $entries = ProductCostSource::query()
            ->with('product:id,sku,name')
            ->where('status', 'confirmed')
            ->when($search !== '', fn ($query) => $query->whereHas('product', fn ($products) => $products
                ->where('sku', 'like', '%'.$search.'%')
                ->orWhere('name', 'like', '%'.$search.'%')))
            ->when($month !== null, fn ($query) => $query
                ->whereDate('effective_from', '>=', $month.'-01')
                ->whereDate('effective_from', '<', date('Y-m-d', strtotime($month.'-01 +1 month'))))
            ->orderByDesc('effective_from')
            ->orderBy('product_id')
            ->orderBy('id')
            ->paginate(100);
        $fx = $this->fxPreview();

        return response()->json([
            'rows' => $entries->getCollection()->map(function (ProductCostSource $source) use ($fx): array {
                $currency = strtoupper((string) $source->currency);
                $cost = (string) $source->unit_cost;
                $usd = $currency === 'USD' ? $cost : null;
                if ($currency === 'CNY' && $fx['available']) {
                    $usd = bcdiv($cost, (string) $fx['usd_to_cny'], 4);
                }

                return [
                    'id' => $source->getKey(),
                    'sku' => $source->product?->sku,
                    'product_name' => $source->product?->name,
                    'effective_from' => $source->effective_from?->format('Y-m-d'),
                    'source_unit_cost' => $cost,
                    'source_currency' => $currency,
                    'usd_reference_cost' => $usd,
                    'source_key' => $source->source_key,
                    'source_reference' => $source->source_reference,
                    'confirmed_at' => $source->confirmed_at?->toIso8601String(),
                ];
            })->values()->all(),
            'pagination' => ['page' => $entries->currentPage(), 'last_page' => $entries->lastPage(), 'total' => $entries->total()],
            'fx' => $fx,
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:20480'],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'csv_month' => ['nullable', 'integer', 'between:1,12'],
        ]);
        $uploaded = $request->file('file');
        $originalName = $uploaded->getClientOriginalName();
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (! in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            throw ValidationException::withMessages(['file' => '仅支持 .xlsx、.xls 或 .csv 原始进销存文件']);
        }
        if ($ext === 'csv' && empty($data['csv_month'])) {
            throw ValidationException::withMessages(['csv_month' => 'CSV 文件必须明确选择月份']);
        }

        try {
            $source = $this->reader->read($uploaded->getRealPath(), (int) $data['year'], isset($data['csv_month']) ? (int) $data['csv_month'] : null, $originalName);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['file' => '无法识别进销存格式：'.$e->getMessage()]);
        }
        if ($source['rows'] === [] || $source['errors'] !== []) {
            throw ValidationException::withMessages(['file' => '文件内没有可用成本记录，或存在无法识别的月份表头：'.implode('；', $source['errors'])]);
        }

        $fingerprint = hash_file('sha256', $uploaded->getRealPath());
        $cacheKey = $this->cacheKey($request, $fingerprint);
        Cache::put($cacheKey, [
            'rows' => $source['rows'],
            'excluded' => $source['excluded'],
            'summary' => $source['summary'],
            'source_reference' => 'monthly_inventory:'.$fingerprint,
            'filename' => $originalName,
            'issued_at' => now()->timestamp,
        ], now()->addMinutes(30));
        $request->session()->put('monthly_cost_import_fingerprint', $fingerprint);
        $preview = $this->planner->resolve($source['rows']);

        return response()->json([
            'fingerprint' => $fingerprint,
            'filename' => $originalName,
            'source_summary' => $source['summary'],
            'excluded' => $source['excluded'],
            'groups' => $preview['groups'],
            'summary' => $preview['summary'],
            'fx' => $this->fxPreview(),
        ]);
    }

    public function plan(Request $request): JsonResponse
    {
        $data = $this->validateResolutionRequest($request);
        $batch = $this->loadBatch($request, $data['fingerprint']);
        $preview = $this->buildPlan($batch, $data['decisions'] ?? []);

        return response()->json(collect($preview)->except('canonical_plans')->all());
    }

    public function confirm(Request $request): JsonResponse
    {
        abort_unless($this->canApply($request), 403, '仅允许已启用的管理员成本批次确认');
        $data = $this->validateResolutionRequest($request);
        $approval = $request->validate([
            'plan_fingerprint' => ['required', 'string', 'size:64'],
            'approval_phrase' => ['required', 'string', 'max:100'],
            'approval_reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        $batch = $this->loadBatch($request, $data['fingerprint']);
        $decisions = $data['decisions'] ?? [];
        $preflight = $this->buildPlan($batch, $decisions);
        if (! hash_equals($preflight['plan_fingerprint'], (string) $request->input('plan_fingerprint'))) {
            throw ValidationException::withMessages(['plan_fingerprint' => '成本或汇率预检已变化，请重新检查并确认']);
        }
        if (! $preflight['can_apply']) {
            throw ValidationException::withMessages(['plan_fingerprint' => '仍存在 SKU、价格或汇率阻断项，请先处理']);
        }
        $expectedPhrase = '确认导入'.$preflight['summary']['canonical_create'].'条成本';
        if (! hash_equals($expectedPhrase, trim((string) $approval['approval_phrase']))) {
            throw ValidationException::withMessages(['approval_phrase' => '请准确输入本批新增成本的确认文字']);
        }

        $outcomes = DB::transaction(function () use ($preflight, $request, $batch, $data, $approval): array {
            $created = 0;
            $noop = 0;
            // Serialize cost confirms for all affected products in MySQL. A second
            // request must re-read committed sources after the first batch finishes.
            $productIds = collect($preflight['canonical_plans'])
                ->flatMap(fn (array $staged) => collect($staged['input_rows'])->pluck('erp_product_id'))
                ->unique()->sort()->values()->all();
            DB::table('products')->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->pluck('id');

            foreach ($preflight['canonical_plans'] as $effectiveFrom => $staged) {
                // Verify canonical product/cost evidence again just before writing.
                $fresh = $this->confirmed->plan($staged['input_rows'], $batch['source_reference'], $effectiveFrom);
                if ($fresh['blocked'] > 0 || $this->planRowsDigest($fresh) !== $this->planRowsDigest($staged['plan'])) {
                    throw ValidationException::withMessages(['plan_fingerprint' => 'ERP 产品成本已变化，导入停止，请重新预检']);
                }
                $result = $this->confirmed->apply($fresh);
                $created += (int) $result['created'];
                $noop += (int) $result['noop'];
            }

            foreach ($preflight['groups'] as $group) {
                if ($group['status'] !== 'ready') {
                    continue;
                }
                $product = $group['product_match'];
                $selectedSource = collect($group['sources'])->firstWhere('source_variant_key', $group['selected_source_variant_key']);
                $this->audit->record(
                    AuditActor::user($request->user(), AuditSource::Web),
                    'product.cost_import.user_confirmed',
                    'product',
                    (int) $product['id'],
                    after: [
                        'erp_sku' => $product['sku'],
                        'effective_from' => $group['effective_from'],
                        'confirmed_cost_cny' => $group['selected_unit_cost_cny'],
                        'original_unit_cost' => $selectedSource['raw_unit_cost'] ?? null,
                        'cost_value_source' => $selectedSource['cost_value_source'] ?? null,
                        'source_sheet' => $selectedSource['source_sheet'] ?? null,
                        'source_row' => $selectedSource['source_row'] ?? null,
                        'source_reference' => $batch['source_reference'],
                        'resolution_reason' => $group['resolution_reason'],
                        'manual_cost_applied' => $group['manual_cost_applied'],
                    ],
                    reason: 'monthly_product_cost_confirmed_import',
                );
            }

            $this->audit->record(
                AuditActor::user($request->user(), AuditSource::Web),
                'product.cost_import.batch_confirmed',
                'monthly_product_cost_import',
                $data['fingerprint'],
                after: [
                    'mode' => app()->environment('production') ? 'production' : 'dev',
                    'source_reference' => $batch['source_reference'],
                    'filename' => $batch['filename'],
                    'plan_fingerprint' => $preflight['plan_fingerprint'],
                    'effective_months' => array_keys($preflight['canonical_plans']),
                    'created' => $created,
                    'noop' => $noop,
                    'approved_phrase' => $approval['approval_phrase'],
                    'approval_reason' => trim((string) $approval['approval_reason']),
                    'usd_to_cny_reference' => $preflight['fx']['usd_to_cny'] ?? null,
                ],
                reason: 'monthly_cost_import_admin_approved',
            );

            return ['created' => $created, 'noop' => $noop];
        });

        Cache::forget($this->cacheKey($request, $data['fingerprint']));
        $request->session()->forget('monthly_cost_import_fingerprint');

        return response()->json($outcomes + ['mode' => app()->environment('production') ? 'production_apply' : 'dev_apply']);
    }

    /** @return array<string,mixed> */
    private function buildPlan(array $batch, array $decisions): array
    {
        $resolution = $this->planner->resolve($batch['rows'], $decisions);
        $canonicalPlans = [];
        $canonicalBlocked = 0;
        $canonicalCreate = 0;
        $canonicalNoop = 0;
        foreach ($resolution['canonical_rows_by_month'] as $month => $rows) {
            $plan = $this->confirmed->plan($rows, $batch['source_reference'], $month);
            $canonicalPlans[$month] = ['input_rows' => $rows, 'plan' => $plan];
            $canonicalBlocked += (int) $plan['blocked'];
            $canonicalCreate += (int) $plan['create'];
            $canonicalNoop += (int) $plan['noop'];
        }
        $fx = $this->fxPreview();
        $groups = $resolution['groups'];
        if ($fx['available']) {
            foreach ($groups as &$group) {
                $cost = $group['selected_unit_cost_cny'];
                $group['usd_reference_cost'] = $cost === null ? null : bcdiv((string) $cost, (string) $fx['usd_to_cny'], 4);
            }
            unset($group);
        }
        $canApply = $resolution['summary']['blocked'] === 0 && $canonicalBlocked === 0 && $canonicalCreate > 0 && $fx['available'];
        $digest = hash('sha256', json_encode([
            'source' => $batch['source_reference'], 'decisions' => $decisions,
            'canonical_plans' => $canonicalPlans, 'fx' => $fx,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return [
            'groups' => $groups,
            'summary' => $resolution['summary'] + ['canonical_blocked' => $canonicalBlocked, 'canonical_create' => $canonicalCreate, 'canonical_noop' => $canonicalNoop],
            'canonical_plans' => $canonicalPlans,
            'fx' => $fx,
            'can_apply' => $canApply,
            'plan_fingerprint' => $digest,
        ];
    }

    private function fxPreview(): array
    {
        $snapshot = $this->fx->snapshot();
        $usd = collect($snapshot['rows'] ?? [])->firstWhere('currency', 'USD');
        $rate = $usd['effectiveRate'] ?? null;
        if (! is_numeric($rate) || (float) $rate <= 0) {
            return ['available' => false, 'reason' => 'USD_CNY_reference_rate_missing'];
        }

        return [
            'available' => true,
            'usd_to_cny' => bcadd((string) $rate, '0', 8),
            'source' => $usd['source'],
            'reference_date' => $snapshot['reference']['referenceDate'] ?? null,
            'stale' => (bool) ($snapshot['reference']['stale'] ?? false),
            'disclosure' => '管理参考美元成本；真实订单以记账时冻结 FX 快照为准',
        ];
    }

    private function validateResolutionRequest(Request $request): array
    {
        return $request->validate([
            'fingerprint' => ['required', 'string', 'size:64'],
            'decisions' => ['nullable', 'array', 'max:2000'],
            'decisions.*' => ['array'],
            'decisions.*.product_id' => ['nullable', 'integer', 'min:1'],
            'decisions.*.source_variant_key' => ['nullable', 'string', 'max:200'],
            'decisions.*.manual_cost_cny' => ['nullable', 'string', 'max:32'],
            'decisions.*.reason' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function loadBatch(Request $request, string $fingerprint): array
    {
        if (! hash_equals((string) $request->session()->get('monthly_cost_import_fingerprint', ''), $fingerprint)) {
            throw ValidationException::withMessages(['fingerprint' => '导入会话已失效，请重新上传']);
        }
        $batch = Cache::get($this->cacheKey($request, $fingerprint));
        if (! is_array($batch) || now()->timestamp - (int) ($batch['issued_at'] ?? 0) > 1800) {
            throw ValidationException::withMessages(['fingerprint' => '导入数据已过期，请重新上传']);
        }

        return $batch;
    }

    private function canApply(Request $request): bool
    {
        if (app()->environment('production')) {
            $user = $request->user();

            return (bool) config('erp_cost_import.production_apply_enabled', false)
                && $user instanceof User
                && (bool) $user->is_active
                && $user->roles()->whereIn('key', ['super_admin', RolePreset::Administrator->roleKey()])->exists();
        }

        return (bool) config('erp_cost_import.dev_apply_enabled', false);
    }

    private function cacheKey(Request $request, string $fingerprint): string
    {
        return 'erp-monthly-cost-import:'.$request->user()->getAuthIdentifier().':'.$fingerprint;
    }

    private function planRowsDigest(array $plan): string
    {
        return hash('sha256', json_encode($plan['rows'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
