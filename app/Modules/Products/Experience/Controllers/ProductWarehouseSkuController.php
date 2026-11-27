<?php

namespace App\Modules\Products\Experience\Controllers;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductWarehouseSku;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A product has one explicitly verified physical SKU per fulfillment warehouse. */
final class ProductWarehouseSkuController
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function upsert(Request $request, int $productId, int $warehouseId): RedirectResponse
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:128'],
            'verification_evidence' => ['required', 'string', 'min:20', 'max:500'],
            'same_physical_product_confirmed' => ['accepted'],
        ]);
        return $this->applyVerified($request,$productId,$warehouseId,$data);
    }

    /** Reused by the verified batch mapping workbook. */
    public function applyVerified(Request $request, int $productId, int $warehouseId, array $data): RedirectResponse
    {
        $product = Product::query()->findOrFail($productId);
        $warehouse = Warehouse::query()->whereKey($warehouseId)->where('is_active', true)->firstOrFail();
        abort_unless(in_array(strtoupper((string) $warehouse->code), (array) config('warehouse_master.fulfillment_codes', []), true),
            422, '该仓库不允许发货 SKU 映射');
        $sku = trim($data['sku']);
        abort_if($sku === '' || strcasecmp($sku, 'gift') === 0, 422, '仓库 SKU 不能是空值或 gift 占位符');

        DB::transaction(function () use ($request, $product, $warehouse, $sku, $data): void {
            Warehouse::query()->whereKey($warehouse->id)->lockForUpdate()->firstOrFail();
            $mapping = ProductWarehouseSku::query()->where('product_id', $product->id)
                ->where('warehouse_id', $warehouse->id)->lockForUpdate()->first();
            if (ProductWarehouseSku::query()->where('warehouse_id', $warehouse->id)
                ->whereRaw('UPPER(TRIM(sku)) = ?', [mb_strtoupper($sku)])->where('product_id', '!=', $product->id)->exists()) {
                throw ValidationException::withMessages(['sku' => '该仓库的实际 SKU 已映射到另一个产品']);
            }
            $before = $mapping?->only(['sku', 'is_active', 'verified_at', 'verification_note']);
            $mapping ??= new ProductWarehouseSku(['product_id' => $product->id, 'warehouse_id' => $warehouse->id]);
            $mapping->forceFill([
                'sku' => $sku, 'is_active' => true,
                'verified_at' => now(),
                'verified_by' => $request->user()->getAuthIdentifier(),
                'verification_note' => trim($data['verification_evidence']),
            ])->save();
            $this->audit->record(
                AuditActor::user($request->user(), AuditSource::Web),
                'product.warehouse_sku_verified', Product::class, $product->id,
                before: $before,
                after: [
                    'warehouse_id' => $warehouse->id, 'warehouse_code' => $warehouse->code,
                    'sku' => $mapping->sku, 'is_active' => true,
                    'verified_at' => $mapping->verified_at?->toIso8601String(),
                ],
                reason: 'verified_warehouse_physical_sku',
            );
        });

        return back()->with('success', '仓库发货 SKU 已登记并核实；已有订单不会自动改写');
    }

    public function deactivate(Request $request, int $productId, int $warehouseId): RedirectResponse
    {
        $request->validate(['confirm_deactivate' => ['accepted']]);
        DB::transaction(function () use ($request, $productId, $warehouseId): void {
            $mapping = ProductWarehouseSku::query()->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)->lockForUpdate()->firstOrFail();
            $mapping->forceFill(['is_active' => false, 'verified_at' => null, 'verified_by' => null])->save();
            $this->audit->record(
                AuditActor::user($request->user(), AuditSource::Web),
                'product.warehouse_sku_deactivated', Product::class, $productId,
                before: ['warehouse_id' => $warehouseId, 'sku' => $mapping->sku, 'is_active' => true],
                after: ['warehouse_id' => $warehouseId, 'sku' => $mapping->sku, 'is_active' => false],
                reason: 'warehouse_physical_sku_deactivated',
            );
        });

        return back()->with('success', '仓库 SKU 映射已停用；待发货订单需要重新核对');
    }
}
