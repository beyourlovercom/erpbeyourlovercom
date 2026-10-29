<?php

namespace App\Modules\Products\Experience\Controllers;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class ProductBarcode69ImportController
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function index(): Response
    {
        return Inertia::render('Products/Barcode69Import');
    }

    public function preview(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:1024']]);
        $handle = fopen($request->file('file')->getRealPath(), 'rb');
        $header = fgetcsv($handle);
        if ($header === false) throw ValidationException::withMessages(['file' => 'CSV无表头']);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        if ($header !== ['sku', 'barcode_69']) {
            throw ValidationException::withMessages(['file' => '表头必须为sku,barcode_69']);
        }
        $source = [];
        $duplicate = [];
        while (($parts = fgetcsv($handle)) !== false) {
            if (count($parts) !== 2) throw ValidationException::withMessages(['file' => 'CSV列数错误']);
            $sku = trim($parts[0]);
            $barcode = trim($parts[1]);
            if ($sku === '' && $barcode === '') continue;
            if ($sku === '' || ! preg_match('/^\d{8,14}$/D', $barcode)) {
                throw ValidationException::withMessages(['file' => 'SKU不能为空，69条形码必须是8至14位数字']);
            }
            if (isset($source[$sku])) $duplicate[$sku] = true;
            $source[$sku] = $barcode;
            if (count($source) > 1000) throw ValidationException::withMessages(['file' => '最多1000条']);
        }
        fclose($handle);
        $rows = [];
        foreach ($source as $sku => $barcode) {
            $matches = Product::query()->where('sku', $sku)->limit(2)->get(['id','sku','barcode_69']);
            $product = $matches->count() === 1 ? $matches->first() : null;
            $status = isset($duplicate[$sku]) ? 'duplicate_source'
                : ($matches->count() > 1 ? 'duplicate_erp'
                : ($product === null ? 'missing_erp'
                : ($product->barcode_69 !== null && $product->barcode_69 !== $barcode ? 'conflict'
                : ($product->barcode_69 === $barcode ? 'unchanged' : 'ready'))));
            $rows[] = [
                'sku' => $sku, 'barcode_69' => $barcode,
                'product_id' => $product?->id, 'current_barcode_69' => $product?->barcode_69,
                'status' => $status,
            ];
        }
        $fingerprint = hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $request->session()->put('barcode69_preview', ['rows' => $rows, 'hash' => $fingerprint]);
        return response()->json(['rows' => $rows, 'fingerprint' => $fingerprint,
            'ready_count' => collect($rows)->where('status', 'ready')->count()]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['fingerprint' => ['required', 'string', 'size:64']]);
        $preview = $request->session()->get('barcode69_preview');
        if (! is_array($preview) || ! hash_equals($preview['hash'], $data['fingerprint'])) {
            throw ValidationException::withMessages(['fingerprint' => '预检已失效，请重新上传']);
        }
        $updated = DB::transaction(function () use ($preview, $request): int {
            $count = 0;
            foreach ($preview['rows'] as $row) {
                if ($row['status'] !== 'ready') continue;
                // Optimistic condition prevents replacing another person's value.
                $affected = Product::query()->whereKey($row['product_id'])
                    ->where('sku', $row['sku'])->whereNull('barcode_69')
                    ->update(['barcode_69' => $row['barcode_69']]);
                if ($affected !== 1) {
                    throw ValidationException::withMessages(['fingerprint' => '产品数据已经变化，请重新预检']);
                }
                $this->audit->record(
                    AuditActor::user($request->user(), AuditSource::Web),
                    'product.barcode_69.imported', Product::class, (int) $row['product_id'],
                    after: ['sku' => $row['sku'], 'barcode_69' => $row['barcode_69']],
                    reason: 'product_barcode_69_confirmed_import'
                );
                $count++;
            }
            return $count;
        });
        $request->session()->forget('barcode69_preview');
        return response()->json(['updated' => $updated, 'skipped' => count($preview['rows']) - $updated]);
    }
}
