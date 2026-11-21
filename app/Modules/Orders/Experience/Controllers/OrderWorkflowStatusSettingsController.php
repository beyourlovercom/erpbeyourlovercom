<?php

namespace App\Modules\Orders\Experience\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Orders\Application\OrderWorkflowStatus;
use App\Modules\Orders\Application\OrderWorkflowStatusDisplayCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class OrderWorkflowStatusSettingsController extends Controller
{
    public function index(OrderWorkflowStatusDisplayCatalog $catalog): Response
    {
        return Inertia::render('Settings/Statuses', [
            'statuses' => $catalog->rows(),
            'defaults' => collect(OrderWorkflowStatus::defaults())->map(fn (array $definition, string $code): array => [
                'code' => $code,
                'sequence' => $definition['sequence'],
                'name_zh' => $definition['zh'],
                'name_en' => $definition['en'],
            ])->values()->all(),
            'version' => $catalog->version(),
        ]);
    }

    public function update(Request $request, OrderWorkflowStatusDisplayCatalog $catalog, AuditRecorder $audit): RedirectResponse
    {
        $defaults = OrderWorkflowStatus::defaults();
        $validated = $request->validate([
            'expected_version' => ['required', 'string', 'size:64'],
            'statuses' => ['required', 'array', 'size:'.count($defaults)],
            'statuses.*.code' => ['required', 'string', Rule::in(array_keys($defaults))],
            'statuses.*.sequence' => ['required', 'string', 'max:16', 'regex:/^(0|[1-9][0-9]{0,2})\\.(0|[1-9][0-9]{0,2})$/'],
            'statuses.*.name_zh' => ['required', 'string', 'max:80'],
            'statuses.*.name_en' => ['required', 'string', 'max:80'],
        ]);

        $submitted = [];
        $sequences = [];
        foreach ($validated['statuses'] as $row) {
            $code = $row['code'];
            $sequence = trim($row['sequence']);
            $zh = trim($row['name_zh']);
            $en = trim($row['name_en']);
            if (isset($submitted[$code]) || isset($sequences[$sequence])
                || $zh === '' || $en === '') {
                throw ValidationException::withMessages([
                    'statuses' => '每个状态必须有唯一的数字序号、非空名称和固定的内部代码。',
                ]);
            }
            $sequences[$sequence] = true;
            $submitted[$code] = compact('sequence', 'zh', 'en');
        }
        if (array_diff(array_keys($defaults), array_keys($submitted)) !== []) {
            throw ValidationException::withMessages(['statuses' => '状态清单不完整，刷新后重试。']);
        }
        if ($catalog->version() !== $validated['expected_version']) {
            throw ValidationException::withMessages(['statuses' => '状态配置已被其他管理员修改，请刷新后重试。']);
        }

        $before = $catalog->rows();
        $userId = (int) $request->user()->getAuthIdentifier();
        DB::transaction(function () use ($defaults, $submitted, $audit, $request, $before, $userId): void {
            DB::table('order_workflow_status_display_settings')->delete();
            $now = now();
            $overrides = [];
            foreach ($defaults as $code => $definition) {
                $next = $submitted[$code];
                if ($next['sequence'] === $definition['sequence'] && $next['zh'] === $definition['zh']
                    && $next['en'] === $definition['en']) {
                    continue;
                }
                $overrides[] = [
                    'code' => $code,
                    'sequence' => $next['sequence'],
                    'name_zh' => $next['zh'],
                    'name_en' => $next['en'],
                    'updated_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($overrides !== []) {
                DB::table('order_workflow_status_display_settings')->insert($overrides);
            }
            $audit->record(
                AuditActor::user($request->user(), AuditSource::Web),
                'order.workflow_status_display_settings_updated',
                'order_workflow_status_display_settings',
                null,
                ['rows' => array_map(static fn (array $row): array => [
                    'code' => $row['code'], 'sequence' => $row['sequence'],
                    'name_zh' => $row['name_zh'], 'name_en' => $row['name_en'],
                ], $before)],
                ['rows' => $submitted],
                'admin_display_only_no_fulfillment_mutation',
            );
        });
        $catalog->clear();

        return back()->with('success', '订单状态名称与序号已保存，全部订单页面将使用新配置。');
    }
}
