<?php

namespace App\Modules\Products\Experience\Controllers;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Products\Application\ProductService;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductParameterSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ProductInlineParameterController
{
    private const MASTER_FIELDS = [
        'weight_kg', 'shipping_weight_kg', 'length_cm', 'width_cm', 'height_cm',
    ];

    private const REVIEW_FIELDS = [
        'material', 'weight_composition', 'net_weight_kg', 'color_box_gross_kg',
        'courier_gross_kg', 'package_length_cm', 'package_width_cm',
        'package_height_cm', 'carton_length_cm', 'carton_width_cm',
        'carton_height_cm', 'units_per_carton', 'carton_gross_weight_kg',
    ];

    public function __construct(
        private readonly ProductService $products,
        private readonly AuditRecorder $audit,
    ) {}

    public function update(Request $request, Product $product): RedirectResponse
    {
        // Only allow the exact fields displayed in the Product360 inline editor.
        // Approved factory measurements are never edited in place.
        $rules = [
            'barcode_69' => ['present', 'nullable', 'string', 'regex:/^[0-9]{8,14}$/'],
            'has_battery' => ['sometimes', 'required', 'boolean'],
            'measurements' => ['sometimes', 'array:'.implode(',', self::REVIEW_FIELDS)],
        ];
        foreach (self::MASTER_FIELDS as $field) {
            $rules[$field] = ['present', 'nullable', 'numeric', 'gt:0', 'max:1000000'];
        }
        foreach (self::REVIEW_FIELDS as $field) {
            $rules['measurements.'.$field] = in_array($field, ['material','weight_composition'], true)
                ? ['sometimes', 'required', 'string', 'max:1000']
                : ['sometimes', 'required', 'numeric', 'gt:0', 'max:1000000'];
        }
        $data = $request->validate($rules);
        $measurements = $data['measurements'] ?? [];
        $masterChanges = [];
        foreach (self::MASTER_FIELDS as $field) {
            $incoming = $data[$field] === null || $data[$field] === '' ? null : (float) $data[$field];
            $current = $product->getAttribute($field);
            if ($incoming !== ($current === null ? null : (float) $current)) {
                $masterChanges[$field] = $incoming;
            }
        }
        if (array_key_exists('has_battery', $data) && (bool) $data['has_battery'] !== (bool) $product->has_battery) {
            $masterChanges['has_battery'] = (bool) $data['has_battery'];
        }
        $barcode = $data['barcode_69'] ?: null;
        $barcodeChanged = $barcode !== $product->barcode_69;
        if (! $masterChanges && ! $barcodeChanged && ! $measurements) {
            return back()->with('success', '参数没有变化');
        }

        DB::transaction(function () use ($request, $product, $masterChanges, $barcodeChanged, $barcode, $measurements): void {
            if ($masterChanges) {
                // Reuse ProductService so weight evidence is created/cleared via the canonical path.
                $before = [];
                foreach (array_keys($masterChanges) as $field) {
                    $before[$field] = $product->getAttribute($field);
                }
                $this->products->updateProduct($product, $masterChanges);
                $product->refresh();
                $after = [];
                foreach (array_keys($masterChanges) as $field) {
                    $after[$field] = $product->getAttribute($field);
                }
                $this->audit->record(
                    AuditActor::user($request->user(), AuditSource::Web),
                    'product.inline_parameters.updated', Product::class, $product->id,
                    before: $before, after: $after,
                    reason: 'product360_inline_master_edit'
                );
            }
            if ($barcodeChanged) {
                $before = $product->barcode_69;
                $product->barcode_69 = $barcode;
                $product->save();
                $this->audit->record(
                    AuditActor::user($request->user(), AuditSource::Web),
                    'product.barcode_69.updated', Product::class, $product->id,
                    before: ['barcode_69' => $before],
                    after: ['barcode_69' => $barcode],
                    reason: 'product_barcode_69_updated'
                );
            }
            if ($measurements) {
                // Admin input enters the *existing* review queue; never spoof approved/reviewed_by.
                $submission = ProductParameterSubmission::query()->create([
                    'product_id' => $product->id,
                    'task_id' => null,
                    'created_by' => $request->user()->id,
                    'status' => 'submitted',
                    'measurements' => $measurements,
                    'accessories' => [],
                    'notes' => '产品360原位编辑提交，待工厂参数审核',
                    'submitted_at' => now(),
                ]);
                $this->audit->record(
                    AuditActor::user($request->user(), AuditSource::Web),
                    'product.parameter_submission.submitted', ProductParameterSubmission::class,
                    $submission->id,
                    after: ['product_id' => $product->id, 'fields' => array_keys($measurements)],
                    reason: 'product360_inline_review_submission'
                );
            }
        });

        return back()->with('success', $measurements
            ? 'ERP主档已保存；工厂参数已提交审核，审核通过前不会覆盖现有值'
            : '产品参数已保存');
    }
}
