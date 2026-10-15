<?php

namespace App\Modules\Products\Experience\Controllers;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductParameterSubmission;
use App\Modules\Products\Infrastructure\Models\ProductParameterTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProductParameterBatchController
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('products.operate'), 403);
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:100'],
            'product_ids.*' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'action' => ['required', Rule::in(['required', 'optional', 'disable'])],
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'required_fields' => ['nullable', 'array', 'max:17'],
            'required_fields.*' => ['required', 'string', 'distinct',
                Rule::in(ProductParameterFactoryController::MEASUREMENT_FIELDS)],
            'allow_reassign' => ['nullable', 'boolean'],
        ]);

        $action = $data['action'];
        $ids = array_map('intval', $data['product_ids']);
        $enabled = $action !== 'disable';
        $assignee = null;
        if ($enabled) {
            $assignee = User::query()->find($data['assigned_user_id'] ?? null);
            if (! $assignee || ! $assignee->is_active ||
                ! $assignee->hasPermission('products.parameters.submit')) {
                throw ValidationException::withMessages([
                    'assigned_user_id' => '选择已启用且具有「填写获指派产品参数」权限的员工',
                ]);
            }
        }
        $requiredFields = array_values($data['required_fields'] ?? []);
        if ($action === 'required' && ! $requiredFields) {
            throw ValidationException::withMessages([
                'required_fields' => '批量设置「必填」时必须指定至少一项必填字段',
            ]);
        }
        // Only explicitly enumerated IDs are touched. Search filters or page size
        // are never accepted as implicit authorization to update other Products.
        DB::transaction(function () use ($request, $ids, $enabled, $assignee, $requiredFields, $action, $data): void {
            $products = Product::query()->whereIn('id', $ids)->lockForUpdate()->pluck('id')->all();
            if (count($products) !== count($ids)) {
                throw ValidationException::withMessages(['product_ids' => '部分产品已不存在，请刷新后重新选择']);
            }
            $tasks = ProductParameterTask::query()->whereIn('product_id', $ids)
                ->lockForUpdate()->get()->keyBy('product_id');
            foreach ($ids as $id) {
                $task = $tasks->get($id);
                if (! $enabled && ! $task) continue; // disable is never a phantom task creation.
                if ($task) {
                    $changingOwner = $enabled && $task->assigned_user_id !== null
                        && (int) $task->assigned_user_id !== (int) $assignee->id;
                    if ($changingOwner && ! ($data['allow_reassign'] ?? false)) {
                        throw ValidationException::withMessages([
                            'allow_reassign' => "产品 #{$id} 已指派其他员工；如需转交，请明确勾选允许重新指派",
                        ]);
                    }
                    if ((! $enabled || $changingOwner) && ProductParameterSubmission::query()
                        ->where('task_id', $task->id)->where('status', 'submitted')->exists()) {
                        throw ValidationException::withMessages([
                            'product_ids' => "产品 #{$id} 存在待审核提交，请先审核再停用或转交",
                        ]);
                    }
                }
            }
            // Every row has passed validation before the first write: fail all
            // atomically, preserve existing drafts/submissions/media/approved parts.
            foreach ($ids as $id) {
                $task = $tasks->get($id);
                if (! $enabled && ! $task) continue;
                $before = $task?->toArray();
                if (! $task) $task = new ProductParameterTask(['product_id' => $id]);
                $task->fill($enabled ? [
                    'assigned_user_id' => $assignee->id,
                    'enabled' => true,
                    'is_required' => $action === 'required',
                    'required_fields' => $action === 'required' ? $requiredFields : [],
                    'updated_by' => $request->user()->id,
                ] : [
                    'enabled' => false, 'is_required' => false,
                    'updated_by' => $request->user()->id,
                ]);
                $task->save();
                $this->audit->record(
                    AuditActor::user($request->user(), AuditSource::Web),
                    'product.parameter_task.bulk_configured',
                    ProductParameterTask::class, $task->id,
                    before: $before,
                    after: [
                        'product_id' => $id, 'enabled' => $task->enabled,
                        'assigned_user_id' => $task->assigned_user_id,
                        'is_required' => $task->is_required,
                        'required_fields' => $task->required_fields,
                    ],
                );
            }
        });

        return back()->with('success', sprintf('已处理 %d 件选中产品的工厂填报任务', count($ids)));
    }
}
