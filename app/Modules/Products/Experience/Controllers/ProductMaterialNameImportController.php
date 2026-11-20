<?php

namespace App\Modules\Products\Experience\Controllers;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Products\Application\MaterialNameImportService;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class ProductMaterialNameImportController
{
    private const SESSION_KEY = 'products.material_name_import.v1';

    public function __construct(
        private readonly MaterialNameImportService $importer,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Products/MaterialNamesImport');
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:10240']]);
        try {
            $result = $this->importer->preview((string) $validated['file']->getRealPath());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
        $fingerprint = hash('sha256', (string) Str::uuid().json_encode(
            $result['rows'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
        $request->session()->put(self::SESSION_KEY, [
            'user_id' => (int) $request->user()->id,
            'fingerprint' => $fingerprint,
            'created_at' => now()->timestamp,
            'rows' => $result['rows'],
        ]);

        return response()->json([...$result, 'fingerprint' => $fingerprint]);
    }

    public function applyReady(Request $request): JsonResponse
    {
        $data = $request->validate(['fingerprint' => ['required', 'string', 'size:64']]);
        $preview = $this->previewFromSession($request, $data['fingerprint']);
        $updated = DB::transaction(function () use (&$preview, $request): int {
            $count = 0;
            foreach ($preview['rows'] as &$row) {
                if ($row['status'] !== 'ready') {
                    continue;
                }
                $product = Product::query()->lockForUpdate()->find($row['product_id']);
                if ($product === null || $product->sku !== $row['product_sku']) {
                    throw ValidationException::withMessages(['fingerprint' => '产品 SKU 已变化，请重新上传预检']);
                }
                if ($product->material_full_name !== null && $product->material_full_name !== '') {
                    if ($product->material_full_name !== $row['material_full_name']) {
                        throw ValidationException::withMessages(['fingerprint' => '物料全称已被其他人修改，请重新预检']);
                    }
                } else {
                    $product->material_full_name = $row['material_full_name'];
                    $product->save();
                    $this->record($request, $product, null, $row, 'auto_unique');
                    $count++;
                }
                $row['status'] = 'unchanged';
                $row['current_name'] = $product->material_full_name;
            }
            unset($row);

            return $count;
        });
        $request->session()->put(self::SESSION_KEY, $preview);

        return response()->json(['updated' => $updated, 'rows' => $preview['rows'],
            'counts' => array_count_values(array_column($preview['rows'], 'status'))]);
    }

    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fingerprint' => ['required', 'string', 'size:64'],
            'row_index' => ['required', 'integer', 'min:0'],
            'product_id' => ['required', 'integer', 'min:1', 'exists:products,id'],
            'material_full_name' => ['required', 'string', 'max:10000'],
            'overwrite_confirmed' => ['sometimes', 'boolean'],
        ]);
        $preview = $this->previewFromSession($request, $data['fingerprint']);
        $rowIndex = (int) $data['row_index'];
        if (! array_key_exists($rowIndex, $preview['rows'])) {
            throw ValidationException::withMessages(['row_index' => '预检行号不存在']);
        }
        if (trim($data['material_full_name']) === '') {
            throw ValidationException::withMessages(['material_full_name' => '请输入物料全称']);
        }
        $row = $preview['rows'][$rowIndex];
        $product = DB::transaction(function () use ($data, $row, $request): Product {
            $product = Product::query()->lockForUpdate()->findOrFail($data['product_id']);
            $old = $product->material_full_name;
            if ($old !== null && $old !== '' && $old !== $data['material_full_name']
                && ! ($data['overwrite_confirmed'] ?? false)) {
                throw ValidationException::withMessages([
                    'overwrite_confirmed' => '产品已有物料全称，需明确勾选覆盖确认',
                ]);
            }
            if ($old !== $data['material_full_name']) {
                $product->material_full_name = $data['material_full_name'];
                $product->save();
                $this->record($request, $product, $old, [
                    ...$row, 'material_full_name' => $data['material_full_name'],
                ], 'manual_row_confirm');
            }

            return $product;
        });
        $preview['rows'][$rowIndex] = [
            ...$row, 'status' => 'resolved', 'product_id' => (int) $product->id,
            'product_sku' => $product->sku, 'current_name' => $product->material_full_name,
        ];
        $request->session()->put(self::SESSION_KEY, $preview);

        return response()->json(['row' => $preview['rows'][$rowIndex],
            'counts' => array_count_values(array_column($preview['rows'], 'status'))]);
    }

    private function previewFromSession(Request $request, string $fingerprint): array
    {
        $preview = $request->session()->get(self::SESSION_KEY);
        if (! is_array($preview)
            || (int) ($preview['user_id'] ?? 0) !== (int) $request->user()->id
            || ! hash_equals((string) ($preview['fingerprint'] ?? ''), $fingerprint)
            || now()->timestamp - (int) ($preview['created_at'] ?? 0) > 3600
            || ! is_array($preview['rows'] ?? null)) {
            throw ValidationException::withMessages(['fingerprint' => '预览已失效，请重新上传 XLSX']);
        }

        return $preview;
    }

    private function record(Request $request, Product $product, ?string $before, array $row, string $mode): void
    {
        $this->audit->record(
            AuditActor::user($request->user(), AuditSource::Web),
            'product.material_full_name.imported', Product::class, (int) $product->id,
            before: ['material_full_name' => $before],
            after: [
                'material_full_name' => $row['material_full_name'],
                'source_sheet' => $row['sheet'],
                'source_row' => $row['source_row'],
                'source_material_code' => $row['material_code'],
                'sku' => $product->sku,
            ],
            reason: 'product_material_full_name_'.$mode,
        );
    }
}
