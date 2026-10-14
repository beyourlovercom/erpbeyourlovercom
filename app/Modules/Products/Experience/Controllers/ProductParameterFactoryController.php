<?php

namespace App\Modules\Products\Experience\Controllers;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductParameterAccessory;
use App\Modules\Products\Infrastructure\Models\ProductParameterMedia;
use App\Modules\Products\Infrastructure\Models\ProductParameterSubmission;
use App\Modules\Products\Infrastructure\Models\ProductParameterTask;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ProductParameterFactoryController
{
    public const MEASUREMENT_FIELDS = [
        'material', 'weight_composition', 'product_length_cm', 'product_width_cm', 'product_height_cm',
        'net_weight_kg', 'color_box_gross_kg', 'courier_gross_kg', 'shipping_weight_kg',
        'package_length_cm', 'package_width_cm', 'package_height_cm',
        'carton_length_cm', 'carton_width_cm', 'carton_height_cm',
        'units_per_carton', 'carton_gross_weight_kg',
    ];

    private const TEXT_FIELDS = ['material', 'weight_composition'];

    public function __construct(private readonly AuditRecorder $audit) {}

    public function inbox(Request $request): Response
    {
        abort_unless($request->user()?->can('inventory.packaging.submit'), 403);
        $query = ProductParameterTask::query()->where('enabled', true)
            ->where('assigned_user_id', $request->user()->id)
            ->with('product:id,sku,name');
        $tasks = $query->orderByDesc('updated_at')->get()->map(function (ProductParameterTask $task): array {
            $latest = ProductParameterSubmission::query()->where('task_id', $task->id)
                ->where('created_by', $task->assigned_user_id)->latest('id')->first();
            $status = in_array($latest?->status, ['submitted', 'approved'], true) ? '已填'
                : ($task->is_required ? '必填' : '待填');
            return ['product_id' => $task->product_id,
                'sku' => $task->product?->sku, 'name' => $task->product?->name,
                'status' => $status, 'latest_status' => $latest?->status,
                'required' => $task->is_required];
        })->all();
        return Inertia::render('Products/FactoryParameterTasks', ['tasks' => $tasks]);
    }

    public function factory(Request $request, Product $product): Response
    {
        $task = $this->assignedTask($request, $product);
        $submissions = ProductParameterSubmission::query()->where('task_id', $task->id)
            ->where('created_by', $request->user()->id)->latest('id')->get();
        $draft = $submissions->firstWhere('status', 'draft');

        return Inertia::render('Products/FactoryParameters', [
            'product' => $product->only(['id', 'sku', 'name']),
            'task' => ['is_required' => $task->is_required, 'required_fields' => $task->required_fields ?? []],
            'draft' => $draft ? $this->serialize($draft->load('media')) : null,
            'history' => $submissions->filter(fn ($s) => $s->status !== 'draft')->take(10)->map(
                fn ($s) => ['id' => $s->id, 'status' => $s->status,
                    'submitted_at' => $s->submitted_at?->toIso8601String(),
                    'review_note' => $s->review_note]
            )->values()->all(),
        ]);
    }

    public function assign(Request $request, Product $product): RedirectResponse
    {
        $this->mustManage($request);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'is_required' => ['required', 'boolean'],
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'required_fields' => ['nullable', 'array', 'max:17'],
            'required_fields.*' => ['string', Rule::in(self::MEASUREMENT_FIELDS)],
        ]);
        if ($data['enabled'] && empty($data['assigned_user_id'])) {
            throw ValidationException::withMessages(['assigned_user_id' => '启用工厂任务前必须指定员工']);
        }
        if ($data['enabled']) {
            $employee = User::query()->find($data['assigned_user_id']);
            if (! $employee || ! $employee->is_active || ! $employee->hasPermission('inventory.packaging.submit')) {
                throw ValidationException::withMessages(['assigned_user_id' => '员工需要启用且拥有包装测量提交权限']);
            }
            if ($data['is_required'] && empty($data['required_fields'])) {
                throw ValidationException::withMessages(['required_fields' => '必填任务必须指定至少一个必填字段']);
            }
        }
        $before = ProductParameterTask::query()->where('product_id', $product->id)->first()?->toArray();
        $task = ProductParameterTask::query()->updateOrCreate(
            ['product_id' => $product->id],
            [
                'enabled' => $data['enabled'],
                'is_required' => $data['enabled'] && $data['is_required'],
                'assigned_user_id' => $data['assigned_user_id'] ?? null,
                'required_fields' => array_values(array_unique($data['required_fields'] ?? [])),
                'updated_by' => $request->user()->id,
            ],
        );
        $this->audit->record(AuditActor::user($request->user(), AuditSource::Web),
            'product.parameter_task.configured', ProductParameterTask::class, $task->id,
            before: $before, after: ['product_id' => $product->id, 'enabled' => $task->enabled,
                'assigned_user_id' => $task->assigned_user_id, 'is_required' => $task->is_required]);
        return back()->with('success', '工厂任务设置已保存');
    }

    public function saveDraft(Request $request, Product $product): RedirectResponse
    {
        $task = $this->assignedTask($request, $product);
        $data = $this->validatedEntry($request);
        $record = DB::transaction(function () use ($request, $task, $product, $data): ProductParameterSubmission {
            $draft = ProductParameterSubmission::query()->where('task_id', $task->id)
                ->where('created_by', $request->user()->id)->where('status', 'draft')
                ->lockForUpdate()->latest('id')->first();
            if (! $draft) {
                $draft = new ProductParameterSubmission(['task_id' => $task->id,
                    'product_id' => $product->id, 'created_by' => $request->user()->id,
                    'status' => 'draft']);
            }
            $draft->fill($data);
            $draft->save();
            return $draft;
        });
        $this->audit($request, 'product.parameter_submission.draft_saved', $record->id,
            null, ['product_id' => $product->id]);
        return back()->with('success', '草稿已保存');
    }

    public function submit(Request $request, Product $product, ProductParameterSubmission $submission): RedirectResponse
    {
        $task = $this->assignedTask($request, $product);
        $this->mustOwnDraft($request, $task, $submission);
        $required = $task->is_required ? ($task->required_fields ?? []) : [];
        $measurements = $submission->measurements ?? [];
        foreach ($required as $field) {
            if (! in_array($field, self::MEASUREMENT_FIELDS, true) || ($measurements[$field] ?? '') === '') {
                throw ValidationException::withMessages(['measurements' => "缺少必填测量字段：{$field}"]);
            }
        }
        if (count(array_filter($measurements, fn ($v) => $v !== null && $v !== '')) === 0
            && count($submission->accessories ?? []) === 0) {
            throw ValidationException::withMessages(['measurements' => '请先填写参数或配件清单']);
        }
        // Photos are deliberately OPTIONAL, unlike order-based Inventory packaging evidence.
        $submission->update(['status' => 'submitted', 'submitted_at' => now()]);
        $this->audit($request, 'product.parameter_submission.submitted', $submission->id,
            null, ['product_id' => $product->id, 'media_count' => $submission->media()->count()]);
        return back()->with('success', '资料已提交审核');
    }

    public function upload(Request $request, Product $product, ProductParameterSubmission $submission): RedirectResponse
    {
        $task = $this->assignedTask($request, $product);
        $this->mustOwnDraft($request, $task, $submission);
        $request->validate(['photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:10240']]);
        abort_if($submission->media()->count() >= 8, 422, '每次提交最多八张照片');
        $file = $request->file('photo');
        $path = $file->store("products/{$product->id}/parameter-evidence/{$submission->id}", 'local');
        try {
            ProductParameterMedia::query()->create([
                'submission_id' => $submission->id, 'disk' => 'local', 'path' => $path,
                'original_name' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
            ]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        $this->audit($request, 'product.parameter_submission.photo_uploaded', $submission->id);
        return back()->with('success', '选填照片已上传');
    }

    public function media(Request $request, ProductParameterMedia $media): StreamedResponse
    {
        $record = ProductParameterSubmission::query()->findOrFail($media->submission_id);
        $product = Product::query()->findOrFail($record->product_id);
        if (! $request->user()?->can('products.operate')) {
            $task = $this->assignedTask($request, $product);
            abort_unless($record->created_by === $request->user()->id && $record->task_id === $task->id, 403);
        }
        abort_unless($media->disk === 'local' && Storage::disk('local')->exists($media->path), 404);
        return Storage::disk('local')->response($media->path, $media->original_name,
            ['Content-Type' => $media->mime_type, 'Cache-Control' => 'private, no-store']);
    }

    public function review(Request $request, Product $product, ProductParameterSubmission $submission): RedirectResponse
    {
        $this->mustManage($request);
        abort_unless($submission->product_id === $product->id && $submission->status === 'submitted', 422);
        abort_unless((int) $submission->created_by !== (int) $request->user()->id, 403, '不能审核自己的填报');
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'review_note' => ['required_if:decision,rejected', 'nullable', 'string', 'max:5000'],
            'apply_accessories' => ['nullable', 'boolean'],
        ]);
        // Fail closed: an approved measurement-only submission cannot silently
        // erase the last approved accessories snapshot, even if a client sends
        // apply_accessories=true directly rather than using the checkbox UI.
        if ($data['decision'] === 'approved' && ($data['apply_accessories'] ?? false)
            && count($submission->accessories ?? []) === 0) {
            throw ValidationException::withMessages(['apply_accessories' =>
                '本次没有配件明细；不能用空清单覆盖已有已审核配件']);
        }
        // Packaging declarations cannot become approved truth if the carton is
        // mathematically too small to contain the stated number of sales packs.
        if ($data['decision'] === 'approved') {
            $m = $submission->measurements ?? [];
            $package = ['package_length_cm','package_width_cm','package_height_cm'];
            $carton = ['carton_length_cm','carton_width_cm','carton_height_cm'];
            if (collect([...$package, ...$carton, 'units_per_carton'])
                ->every(fn ($key) => is_numeric($m[$key] ?? null) && (float) $m[$key] > 0)) {
                $productVolume = array_product(array_map(fn ($key) => (float) $m[$key], $package));
                $cartonVolume = array_product(array_map(fn ($key) => (float) $m[$key], $carton));
                if ($cartonVolume < $productVolume * (float) $m['units_per_carton']) {
                    throw ValidationException::withMessages(['measurements' =>
                        '单件包装体积 × 装箱数量超过外箱容积，请工厂核实后重新提交']);
                }
            }
        }
        DB::transaction(function () use ($request, $product, $submission, $data): void {
            $record = ProductParameterSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            abort_unless($record->product_id === $product->id && $record->status === 'submitted', 422);
            $record->update(['status' => $data['decision'], 'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(), 'review_note' => $data['review_note'] ?? null]);
            if ($data['decision'] === 'approved' && ($data['apply_accessories'] ?? false)) {
                ProductParameterAccessory::query()->where('product_id', $product->id)->delete();
                foreach ($record->accessories ?? [] as $part) {
                    ProductParameterAccessory::query()->create([
                        'product_id' => $product->id, 'approved_submission_id' => $record->id,
                        'name' => $part['name'], 'quantity' => $part['quantity'],
                        'unit' => $part['unit'], 'notes' => $part['notes'] ?? null,
                    ]);
                }
            }
        });
        // Approval of evidence does NOT auto-change products.weight_kg or fulfillment snapshots.
        $this->audit($request, 'product.parameter_submission.'.$data['decision'],
            $submission->id, null, ['product_id' => $product->id, 'review_note' => $data['review_note'] ?? null,
                'apply_accessories' => $data['apply_accessories'] ?? false]);
        return back()->with('success', '审核结果已记录');
    }

    private function assignedTask(Request $request, Product $product): ProductParameterTask
    {
        abort_unless($request->user()?->can('inventory.packaging.submit'), 403);
        $task = ProductParameterTask::query()->where('product_id', $product->id)
            ->where('enabled', true)->where('assigned_user_id', $request->user()->id)->first();
        abort_unless($task !== null, 403);
        return $task;
    }

    private function mustManage(Request $request): void
    {
        abort_unless($request->user()?->can('products.operate'), 403);
    }

    private function mustOwnDraft(Request $request, ProductParameterTask $task, ProductParameterSubmission $s): void
    {
        abort_unless((int) $s->product_id === (int) $task->product_id &&
            (int) $s->task_id === (int) $task->id &&
            (int) $s->created_by === (int) $request->user()->id && $s->status === 'draft', 403);
    }

    private function validatedEntry(Request $request): array
    {
        $rules = ['measurements' => ['nullable', 'array:'.implode(',', self::MEASUREMENT_FIELDS)],
            'accessories' => ['nullable', 'array', 'max:40'],
            'accessories.*' => ['required', 'array:name,quantity,unit,notes'],
            'accessories.*.name' => ['required', 'string', 'max:200'],
            'accessories.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'accessories.*.unit' => ['required', 'string', 'max:32'],
            'accessories.*.notes' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
        foreach (self::MEASUREMENT_FIELDS as $field) {
            $rules['measurements.'.$field] = in_array($field, self::TEXT_FIELDS, true)
                ? ['nullable', 'string', 'max:1000']
                : ['nullable', 'numeric', 'gt:0', 'max:1000000'];
        }
        $data = $request->validate($rules);
        return ['measurements' => $data['measurements'] ?? [], 'accessories' => $data['accessories'] ?? [],
            'notes' => $data['notes'] ?? null];
    }

    private function serialize(ProductParameterSubmission $s): array
    {
        return ['id' => $s->id, 'status' => $s->status,
            'measurements' => $s->measurements ?? [], 'accessories' => $s->accessories ?? [],
            'notes' => $s->notes, 'media' => $s->media->map(fn ($m) => ['id' => $m->id,
                'name' => $m->original_name, 'url' => route('products.parameter.media', $m->id)])->all()];
    }

    private function audit(Request $request, string $action, int $id,
        ?array $before = null, ?array $after = null): void
    {
        $this->audit->record(AuditActor::user($request->user(), AuditSource::Web),
            $action, ProductParameterSubmission::class, $id, before: $before, after: $after);
    }
}
