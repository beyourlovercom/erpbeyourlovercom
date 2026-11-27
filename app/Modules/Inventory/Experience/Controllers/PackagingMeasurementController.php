<?php

namespace App\Modules\Inventory\Experience\Controllers;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Identity\Application\AuthorizationKeys;
use App\Modules\Inventory\Infrastructure\Models\PackagingMeasurementMedia;
use App\Modules\Inventory\Infrastructure\Models\PackagingMeasurementRecord;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\NotificationCenter\Application\NotificationPublisher;
use App\Modules\Products\Application\PackagingPresetCatalog;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductPackagingProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class PackagingMeasurementController
{
    public function __construct(private readonly AuditRecorder $audit, private readonly PackagingPresetCatalog $presets, private readonly NotificationPublisher $notifications) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->can(AuthorizationKeys::InventoryPackagingSubmit->value) || $user->can(AuthorizationKeys::InventoryPackagingReview->value) || $user->can(AuthorizationKeys::InventoryView->value), 403);
        $reviewer = $user->can(AuthorizationKeys::InventoryPackagingReview->value) || $user->can(AuthorizationKeys::InventoryView->value);
        $query = PackagingMeasurementRecord::query()->with(['product:id,sku,name','warehouse:id,name','creator:id,name','media']);
        if (! $reviewer) $query->where('created_by', $user->getAuthIdentifier());
        if ($request->filled('status')) $query->where('status', $request->string('status'));
        if ($request->filled('search')) $query->whereHas('product', fn ($q) => $q->where('sku', 'like', '%'.$request->string('search').'%')->orWhere('name', 'like', '%'.$request->string('search').'%'));
        $records = $query->latest('id')->paginate(20)->withQueryString()->through(fn (PackagingMeasurementRecord $r) => $this->summary($r));
        return Inertia::render('Inventory/Packaging/Index', ['records'=>$records, 'filters'=>$request->only(['status','search']), 'canReview'=>$reviewer, 'canSubmit'=>$user->can(AuthorizationKeys::InventoryPackagingSubmit->value)]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeSubmit($request);
        return Inertia::render('Inventory/Packaging/Form', [
            'record'=>null,
            'warehouses'=>$this->warehouses(),
            'packagingPresets'=>$this->presets->all(),
            'packagingSop'=>null,
        ]);
    }

    public function show(Request $request, PackagingMeasurementRecord $record): Response
    {
        $this->authorizeRead($request, $record);
        $record->load(['product:id,sku,name','warehouse:id,name','creator:id,name','reviewer:id,name','media']);
        $profile = $this->activeProfile($record->product_id);
        return Inertia::render('Inventory/Packaging/Show', [
            'record'=>$this->detail($record),
            'packagingSop'=>$this->guideForMethod($record->packaging_method_key, $profile),
            'packagingPresets'=>$this->presets->all(),
            'currentStandardRecordId'=>$profile?->current_measurement_record_id,
            'isCurrentStandard'=>(int) ($profile?->current_measurement_record_id ?? 0) === (int) $record->id,
            'canAdoptStandard'=>$record->status === 'approved' && $request->user()->can(AuthorizationKeys::InventoryPackagingReview->value),
            'canReview'=>$request->user()->can(AuthorizationKeys::InventoryPackagingReview->value),
            'canResubmit'=>$record->status === 'rejected' && (int) $record->created_by === (int) $request->user()->getAuthIdentifier() && $request->user()->can(AuthorizationKeys::InventoryPackagingSubmit->value),
            'canEdit'=>$record->status === 'draft' && $record->created_by === $request->user()->getAuthIdentifier(),
        ]);
    }

    public function edit(Request $request, PackagingMeasurementRecord $record): Response
    {
        $this->authorizeOwnerDraft($request, $record);
        $record->load(['product:id,sku,name','warehouse:id,name','media']);
        $profile = $this->activeProfile($record->product_id);
        return Inertia::render('Inventory/Packaging/Form', [
            'record'=>$this->detail($record),
            'warehouses'=>$this->warehouses(),
            'packagingPresets'=>$this->presets->all(),
            'packagingSop'=>$profile && $this->hasPackagingGuide($profile) ? $this->packagingProfile($profile) : null,
        ]);
    }

    public function productGuide(Request $request, Product $product): JsonResponse
    {
        $this->authorizeSubmit($request);
        $profile = $this->activeProfile($product->id);
        return response()->json([
            'presets'=>$this->presets->all(),
            'packaging_sop'=>$profile && $this->hasPackagingGuide($profile) ? $this->packagingProfile($profile) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeSubmit($request);
        $record = PackagingMeasurementRecord::create([...$this->validated($request, false), 'status'=>'draft', 'created_by'=>$request->user()->getAuthIdentifier()]);
        $this->audit($request, 'packaging_measurement.created', $record, 'packaging_measurement_created');
        return redirect()->route('inventory.packaging.edit', $record)->with('success', '草稿已保存，可直接填写测量数据或选传图片');
    }

    public function update(Request $request, PackagingMeasurementRecord $record): RedirectResponse
    {
        $this->authorizeOwnerDraft($request, $record);
        $data = $this->validated($request, false);
        DB::transaction(function () use ($request, $record, $data): void {
            $record = PackagingMeasurementRecord::query()->lockForUpdate()->findOrFail($record->id);
            $this->authorizeOwnerDraft($request, $record);
            $this->guardEvidenceProduct($record, $data);
            $record->update($data);
            $this->audit($request, 'packaging_measurement.updated', $record, 'packaging_measurement_updated');
        });
        return back()->with('success', '草稿已更新');
    }

    public function upload(Request $request, PackagingMeasurementRecord $record): RedirectResponse
    {
        $this->authorizeOwnerDraft($request, $record);
        $data = $request->validate(['kind'=>['required', Rule::in(['packaging','weight','measurement'])], 'files'=>['required','array','min:1'], 'files.*'=>['required','file','image','mimes:jpeg,jpg,png,webp','max:10240']]);
        $stored = [];
        try {
            DB::transaction(function () use ($request, $record, $data, &$stored): void {
                $record = PackagingMeasurementRecord::query()->lockForUpdate()->findOrFail($record->id);
                $this->authorizeOwnerDraft($request, $record);
                foreach ($request->file('files', []) as $file) {
                    $path = $file->store('inventory/packaging-evidence/'.$record->id.'/'.$data['kind'], 'local');
                    $stored[] = $path;
                    $record->media()->create(['kind'=>$data['kind'], 'disk'=>'local', 'path'=>$path, 'original_name'=>$file->getClientOriginalName(), 'mime_type'=>$file->getMimeType(), 'size_bytes'=>$file->getSize(), 'sort_order'=>$record->media()->where('kind', $data['kind'])->count()]);
                }
            });
        } catch (\Throwable $e) {
            foreach ($stored as $path) Storage::disk('local')->delete($path);
            throw $e;
        }
        return back()->with('success', '证据图片已上传');
    }

    public function removeMedia(Request $request, PackagingMeasurementMedia $media): RedirectResponse
    {
        DB::transaction(function () use ($request, $media): void {
            $record = PackagingMeasurementRecord::query()->lockForUpdate()->findOrFail($media->record_id);
            $this->authorizeOwnerDraft($request, $record);
            $lockedMedia = $record->media()->whereKey($media->id)->firstOrFail();
            $lockedMedia->delete();
            DB::afterCommit(fn () => Storage::disk($lockedMedia->disk)->delete($lockedMedia->path));
        });
        return back()->with('success', '图片已删除');
    }

    public function submit(Request $request, PackagingMeasurementRecord $record): RedirectResponse
    {
        $this->authorizeOwnerDraft($request, $record);
        $data = $this->validated($request, true);
        $record = DB::transaction(function () use ($request, $record, $data): PackagingMeasurementRecord {
            $record = PackagingMeasurementRecord::query()->lockForUpdate()->findOrFail($record->id);
            $this->authorizeOwnerDraft($request, $record);
            $this->guardEvidenceProduct($record, $data);
            $reason = $this->photoRequirement($record, $data);
            if ($reason && ! $this->hasRequiredPhotos($record)) {
                throw ValidationException::withMessages(['photos'=>$reason === 'reviewer_requested'
                    ? '运营要求拍照复核，请上传尺寸图及称重图。'
                    : '本次测量与已采用的同包装标准差异明显，请核对数值；确认无误后上传尺寸图及称重图。']);
            }
            $record->update([...$data, 'photo_requirement_reason'=>$reason, 'status'=>'submitted', 'submitted_at'=>now()]);
            $this->audit($request, 'packaging_measurement.submitted', $record, 'packaging_measurement_submitted');
            return $record;
        });
        $record->loadMissing('product:id,sku,name');
        $this->notifications->publishToPermission(AuthorizationKeys::InventoryPackagingReview->value, [
            'category'=>'inventory',
            'title'=>'新的包装测量待审核',
            'message'=>$record->product->sku.' 已提交包装尺寸与重量证据。',
            'action_url'=>'/inventory/packaging/'.$record->id,
            'entity_type'=>'packaging_measurement',
            'entity_id'=>$record->id,
            'actor_name'=>$request->user()->name,
        ], (int) $request->user()->getAuthIdentifier());
        return redirect()->route('inventory.packaging.show', $record)->with('success', '记录已提交审核');
    }

    public function review(Request $request, PackagingMeasurementRecord $record): RedirectResponse
    {
        abort_unless($record->status === 'submitted', 422, '只有待审核记录可以审核');
        $data = $request->validate(['decision'=>['required', Rule::in(['approved','rejected'])], 'review_note'=>['required_if:decision,rejected','nullable','string','max:5000'], 'require_photos'=>['sometimes','boolean']]);
        abort_if(($data['require_photos'] ?? false) && $data['decision'] !== 'rejected', 422, '拍照复核必须通过驳回发起');
        $record = DB::transaction(function () use ($request, $record, $data): PackagingMeasurementRecord {
            $record = PackagingMeasurementRecord::query()->lockForUpdate()->findOrFail($record->id);
            abort_unless($record->status === 'submitted', 409, '记录已被处理，请刷新');
            if ($data['decision'] === 'approved' && $record->photo_requirement_reason && ! $this->hasRequiredPhotos($record)) {
                throw ValidationException::withMessages(['photos'=>'本记录要求拍照复核，证据不完整。']);
            }
            $record->update(['photo_requirement_reason'=>($data['require_photos'] ?? false) ? 'reviewer_requested' : $record->photo_requirement_reason, 'status'=>$data['decision'], 'review_note'=>$data['review_note'] ?? null, 'reviewed_by'=>$request->user()->getAuthIdentifier(), 'reviewed_at'=>now()]);
            $this->audit($request, 'packaging_measurement.'.$data['decision'], $record, 'packaging_measurement_'.$data['decision']);
            return $record;
        });
        $record->loadMissing(['product:id,sku,name','creator:id,name']);
        if ($record->creator && (int) $record->creator->id !== (int) $request->user()->getAuthIdentifier()) {
            $approved = $data['decision'] === 'approved';
            $this->notifications->publishToUser($record->creator, [
                'category'=>'inventory',
                'title'=>$approved ? '包装测量已批准' : '包装测量已驳回',
                'message'=>$record->product->sku.($approved ? ' 的包装尺寸与重量证据已通过审核。' : ' 的包装尺寸与重量证据需要调整。'),
                'action_url'=>'/inventory/packaging/'.$record->id,
                'required_permission'=>AuthorizationKeys::InventoryPackagingSubmit->value,
                'entity_type'=>'packaging_measurement',
                'entity_id'=>$record->id,
                'related_links'=>[['label'=>'包装记录','value'=>'#'.$record->id,'url'=>'/inventory/packaging/'.$record->id], ['label'=>'相关产品','value'=>$record->product->sku,'url'=>'/products/'.$record->product_id]],
                'related_links'=>[['label'=>'包装记录','value'=>'#'.$record->id,'url'=>'/inventory/packaging/'.$record->id], ['label'=>'相关产品','value'=>$record->product->sku,'url'=>'/products/'.$record->product_id]],
                'actor_name'=>$request->user()->name,
            ]);
        }
        return back()->with('success', $data['decision'] === 'approved' ? '已批准' : '已驳回');
    }

    public function resubmit(Request $request, PackagingMeasurementRecord $record): RedirectResponse
    {
        $this->authorizeSubmit($request);
        $next = DB::transaction(function () use ($request, $record): PackagingMeasurementRecord {
            $record = PackagingMeasurementRecord::query()->lockForUpdate()->findOrFail($record->id);
            abort_unless((int) $record->created_by === (int) $request->user()->getAuthIdentifier(), 403);
            abort_unless($record->status === 'rejected', 422, '只有已驳回记录可以重新测量');
            $existing = PackagingMeasurementRecord::query()->where('resubmission_of_id', $record->id)->first();
            if ($existing) {
                abort_unless($existing->status === 'draft', 409, '已存在重新提交记录');
                return $existing;
            }
            // Original evidence remains immutable; only explicit or abnormal review requires new photos.
            $next = PackagingMeasurementRecord::create([
                ...$record->only(['product_id','warehouse_id','packaging_method_key']),
                'resubmission_of_id'=>$record->id, 'photo_requirement_reason'=>$record->photo_requirement_reason, 'status'=>'draft', 'created_by'=>$request->user()->getAuthIdentifier(),
            ]);
            $this->audit->record(AuditActor::user($request->user(), AuditSource::Web),
                'packaging_measurement.resubmission_created', $next::class, $next->id,
                after: ['resubmission_of_id'=>$record->id, 'product_id'=>$record->product_id],
                reason: 'packaging_measurement_rejected_resubmission');
            return $next;
        });
        return redirect()->route('inventory.packaging.edit', $next)->with('success', '请重新填写测量数据；原驳回记录保留，按复核要求上传图片');
    }

    public function adoptStandard(Request $request, PackagingMeasurementRecord $record): RedirectResponse
    {
        abort_unless($record->status === 'approved', 422, '只有已批准记录可以设为当前包装标准');
        $expectation = $request->validate(['expected_current_standard_record_id'=>['sometimes','nullable','integer']]);
        DB::transaction(function () use ($request, $record, $expectation): void {
            Product::query()->lockForUpdate()->findOrFail($record->product_id);
            $record = PackagingMeasurementRecord::query()->lockForUpdate()->findOrFail($record->id);
            abort_unless($record->status === 'approved', 409, '记录状态已变化');
            abort_unless($record->packaged_weight_kg > 0 && $record->packaging_length_cm > 0 && $record->packaging_width_cm > 0 && $record->packaging_height_cm > 0, 422, '包装测量不完整');
            abort_if($record->photo_requirement_reason && ! $this->hasRequiredPhotos($record), 422, '拍照复核证据不完整');
            $profile = ProductPackagingProfile::query()->where('product_id',$record->product_id)->lockForUpdate()->first()
                ?? new ProductPackagingProfile(['product_id'=>$record->product_id]);
            $previous = $profile->current_measurement_record_id;
            if (array_key_exists('expected_current_standard_record_id', $expectation)) {
                abort_unless((int) $previous === (int) $expectation['expected_current_standard_record_id'], 409, '包装标准已变化，请刷新后重新确认');
            }
            if ((int) $previous === (int) $record->id && $profile->is_active) return;
            if (in_array($record->packaging_method_key, $this->presets->keys(), true)) {
                $profile->preset_key = $record->packaging_method_key;
            }
            if ($record->packaging_method_key === 'custom') {
                $profile->preset_key = null;
                $profile->custom_method ??= '定制包装方案';
            }
            $profile->current_measurement_record_id = $record->id;
            $profile->is_active = true;
            $profile->save();
            $this->audit->record(
                AuditActor::user($request->user(), AuditSource::Web),
                'packaging_measurement.adopted_as_current_standard',
                $record::class, $record->id,
                before: ['current_measurement_record_id'=>$previous],
                after: ['product_id'=>$record->product_id, 'current_measurement_record_id'=>$record->id, 'adopted_at'=>now()->toIso8601String()],
                reason: 'packaging_measurement_current_standard_adopted',
            );
        });
        return back()->with('success', '已设为该产品当前包装标准；后续订单/履约将自动调用');
    }

    public function media(Request $request, PackagingMeasurementMedia $media)
    {
        $this->authorizeRead($request, $media->record()->firstOrFail());
        abort_unless(Storage::disk($media->disk)->exists($media->path), 404);
        return Storage::disk($media->disk)->response($media->path, $media->original_name, ['Content-Type'=>$media->mime_type, 'Cache-Control'=>'private, no-store'], 'inline');
    }

    private function validated(Request $request, bool $submit): array
    {
        $methodKeys = $this->presets->keys();
        $methodKeys[] = 'custom';
        return $request->validate([
            'product_id'=>['required','integer','exists:products,id'],
            'warehouse_id'=>['nullable','integer','exists:warehouses,id'],
            'packaging_method_key'=>['nullable','string',Rule::in($methodKeys)],
            'packaging_length_cm'=>[$submit?'required':'nullable','numeric','min:0.01','max:99999999.99'],
            'packaging_width_cm'=>[$submit?'required':'nullable','numeric','min:0.01','max:99999999.99'],
            'packaging_height_cm'=>[$submit?'required':'nullable','numeric','min:0.01','max:99999999.99'],
            'packaged_weight_kg'=>[$submit?'required':'nullable','numeric','min:0.001','max:9999999.999'],
            'measured_at'=>[$submit?'required':'nullable','date'],
            'note'=>['nullable','string','max:5000'],
        ]);
    }

    private function hasRequiredPhotos(PackagingMeasurementRecord $record): bool
    {
        return $record->media()->where('kind','measurement')->exists()
            && $record->media()->where('kind','weight')->exists();
    }

    /** Only comparable adopted measurements qualify; a new packaging method is not itself anomalous. */
    private function photoRequirement(PackagingMeasurementRecord $record, array $data): ?string
    {
        if ($record->photo_requirement_reason) return $record->photo_requirement_reason;
        $method = $data['packaging_method_key'] ?? $record->packaging_method_key;
        if (! $method) return null;
        $profile = ProductPackagingProfile::query()->where('product_id', $data['product_id'])->where('is_active', true)->first();
        if (! $profile?->current_measurement_record_id) return null;
        $prior = PackagingMeasurementRecord::query()->whereKey($profile->current_measurement_record_id)
            ->where('product_id', $data['product_id'])->where('status','approved')->where('packaging_method_key',$method)->first();
        if (! $prior) return null;
        $keys = ['packaged_weight_kg','packaging_length_cm','packaging_width_cm','packaging_height_cm'];
        foreach ($keys as $key) if (! is_numeric($prior->$key) || (float) $prior->$key <= 0) return null;
        $weightRatio = (float) $data['packaged_weight_kg'] / (float) $prior->packaged_weight_kg;
        $volumeRatio = 1.0;
        foreach (array_slice($keys, 1) as $key) $volumeRatio *= (float) $data[$key] / (float) $prior->$key;
        // Conservative operational default: at least double or at most half the adopted weight or volume.
        return min($weightRatio,$volumeRatio) <= 0.5 || max($weightRatio,$volumeRatio) >= 2.0
            ? 'material_deviation' : null;
    }

    private function guardEvidenceProduct(PackagingMeasurementRecord $record, array $data): void
    {
        if ($record->resubmission_of_id || $record->media()->exists()) {
            abort_unless((int) $record->product_id === (int) $data['product_id'], 422, '有证据或重传历史的记录不能更换商品');
        }
    }

    private function authorizeSubmit(Request $request): void { abort_unless($request->user()->can(AuthorizationKeys::InventoryPackagingSubmit->value), 403); }
    private function authorizeOwnerDraft(Request $request, PackagingMeasurementRecord $record): void { $this->authorizeSubmit($request); abort_unless($record->created_by === $request->user()->getAuthIdentifier() && $record->status === 'draft', 403); }
    private function authorizeRead(Request $request, PackagingMeasurementRecord $record): void { $user=$request->user(); abort_unless($user->can(AuthorizationKeys::InventoryPackagingReview->value) || $user->can(AuthorizationKeys::InventoryView->value) || ($user->can(AuthorizationKeys::InventoryPackagingSubmit->value) && $record->created_by === $user->getAuthIdentifier()), 403); }
    private function audit(Request $request, string $action, PackagingMeasurementRecord $record, string $reason): void { $this->audit->record(AuditActor::user($request->user(), AuditSource::Web), $action, $record::class, $record->id, after: ['status'=>$record->status,'photo_requirement_reason'=>$record->photo_requirement_reason], reason: $reason); }
    private function warehouses() { return Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id','name']); }
    private function activeProfile(int $productId): ?ProductPackagingProfile { return ProductPackagingProfile::query()->with('media')->where('product_id', $productId)->where('is_active', true)->first(); }

    private function summary(PackagingMeasurementRecord $r): array
    {
        return [
            'id'=>$r->id,'status'=>$r->status,'packaging_method_key'=>$r->packaging_method_key,
            'product'=>['id'=>$r->product->id,'sku'=>$r->product->sku,'name'=>$r->product->name],
            'warehouse'=>$r->warehouse?->name,'operator'=>$r->creator?->name,'measured_at'=>$r->measured_at?->toIso8601String(),
            'dimensions'=>implode(' × ',[$r->packaging_length_cm,$r->packaging_width_cm,$r->packaging_height_cm]).' cm',
            'packaged_weight_kg'=>$r->packaged_weight_kg,'media_count'=>$r->media->count(),
        ];
    }

    private function detail(PackagingMeasurementRecord $r): array
    {
        return [...$this->summary($r), 'warehouse_id'=>$r->warehouse_id,'packaging_length_cm'=>$r->packaging_length_cm,
            'packaging_width_cm'=>$r->packaging_width_cm,'packaging_height_cm'=>$r->packaging_height_cm,'packaged_weight_kg'=>$r->packaged_weight_kg,
            'resubmission_of_id'=>$r->resubmission_of_id,'photo_requirement_reason'=>$r->photo_requirement_reason,'note'=>$r->note,'submitted_at'=>$r->submitted_at?->toIso8601String(),'reviewer'=>$r->reviewer?->name,'reviewed_at'=>$r->reviewed_at?->toIso8601String(),
            'review_note'=>$r->review_note,'media'=>$r->media->map(fn($m)=>['id'=>$m->id,'kind'=>$m->kind,'name'=>$m->original_name,'mime_type'=>$m->mime_type,'size_bytes'=>$m->size_bytes,'url'=>route('inventory.packaging.media',$m)])->values()];
    }

    private function guideForMethod(?string $methodKey, ?ProductPackagingProfile $profile): ?array
    {
        if ($methodKey === null || $methodKey === '') return null;
        if ($methodKey === 'custom') {
            return $profile && $profile->preset_key === null ? $this->packagingProfile($profile) : null;
        }
        if ($profile && $profile->preset_key === $methodKey) return $this->packagingProfile($profile);
        return $this->presets->guide($methodKey);
    }

    private function hasPackagingGuide(ProductPackagingProfile $profile): bool
    {
        return $profile->preset_key !== null
            || $profile->custom_method !== null
            || $profile->custom_materials !== null
            || $profile->custom_steps !== null
            || $profile->custom_tips !== null
            || ($profile->relationLoaded('media') ? $profile->media->isNotEmpty() : $profile->media()->exists());
    }

    private function packagingProfile(ProductPackagingProfile $profile): array
    {
        $base = $profile->preset_key ? $this->presets->guide($profile->preset_key) : null;
        $media = $profile->relationLoaded('media') ? $profile->media : $profile->media()->get();
        $defaultMedia = $media->firstWhere('kind', 'default');
        $renderedMedia = $media->firstWhere('kind', 'rendered');
        $uploadedExamples = $media->where('kind', 'example')->values()->map(fn ($item) => [
            'title'=>$item->original_name ?: '真实包装案例',
            'image_url'=>route('products.packaging.media', $item),
        ])->all();
        return [
            'id'=>$profile->id,
            'title'=>$base['title'] ?? '产品定制包装教程',
            'method'=>$base['method'] ?? $profile->custom_method ?? '定制包装方案',
            'preset_key'=>$profile->preset_key,
            'scenario'=>$profile->scenario ?: ($base['scenario'] ?? null),
            'version'=>$profile->version ?: ($base['version'] ?? 'custom-v1'),
            'default_illustration_url'=>$defaultMedia ? route('products.packaging.media', $defaultMedia) : ($base['default_illustration_url'] ?? null),
            'rendered_illustration_url'=>$renderedMedia ? route('products.packaging.media', $renderedMedia) : null,
            'rendered_illustration_name'=>$renderedMedia?->original_name,
            'materials'=>$profile->custom_materials ?? ($base['materials'] ?? []),
            'steps'=>$profile->custom_steps ?? ($base['steps'] ?? []),
            'tips'=>$profile->custom_tips ?? ($base['tips'] ?? []),
            'examples'=>$uploadedExamples,
        ];
    }
}
