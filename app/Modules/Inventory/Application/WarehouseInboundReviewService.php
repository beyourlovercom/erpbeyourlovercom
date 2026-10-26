<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Inventory\Infrastructure\Models\WarehouseInboundBatch;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WarehouseInboundReviewService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function review(
        Warehouse $warehouse,
        int $batchId,
        int $itemId,
        int $productId,
        string $expectedUpdatedAt,
        string $reason,
        ?Authenticatable $actor,
    ): void {
        DB::transaction(function () use ($warehouse, $batchId, $itemId, $productId, $expectedUpdatedAt, $reason, $actor): void {
            // Same parent lock as lifecycle advance: review and activation cannot race.
            $batch = WarehouseInboundBatch::query()
                ->where('warehouse_id', $warehouse->id)
                ->lockForUpdate()
                ->findOrFail($batchId);

            if ($batch->status !== 'received') {
                throw ValidationException::withMessages(['product_id' => '仅仓库已入库、尚未激活的批次允许复核。']);
            }

            $item = $batch->items()->lockForUpdate()->findOrFail($itemId);
            if ($item->match_status === 'matched' && $item->product_id !== null) {
                throw ValidationException::withMessages(['product_id' => '该产品已匹配，请刷新页面，勿重复复核。']);
            }

            if (! $item->updated_at || ! $item->updated_at->equalTo(Carbon::parse($expectedUpdatedAt))) {
                throw ValidationException::withMessages(['expected_updated_at' => '入库明细已被其他操作更新，请刷新后重新复核。']);
            }

            $product = Product::query()->whereKey($productId)->where('is_active', true)->first();
            if (! $product || trim((string) $product->sku) === '') {
                throw ValidationException::withMessages(['product_id' => '只能匹配 Product OS 中已启用且具有正式 SKU 的产品。']);
            }

            $before = $item->only([
                'batch_id', 'product_id', 'source_sku', 'canonical_sku', 'source_product_name',
                'quantity', 'evidence_type', 'match_status', 'note',
            ]);
            // Source manifest evidence and quantities are immutable in this workflow.
            $item->product_id = $product->id;
            $item->canonical_sku = $product->sku;
            $item->match_status = 'matched';
            $item->save();

            $this->audit->record(
                $actor ? AuditActor::user($actor, AuditSource::InternalSystem) : AuditActor::system(AuditSource::InternalSystem),
                'warehouse.inbound_sku_reviewed',
                $item::class,
                $item->id,
                before: $before,
                after: [
                    ...$item->only([
                        'batch_id', 'product_id', 'source_sku', 'canonical_sku', 'source_product_name',
                        'quantity', 'evidence_type', 'match_status', 'note',
                    ]),
                    'warehouse_id' => $warehouse->id,
                    'batch_reference' => $batch->reference,
                    'review_reason' => $reason,
                ],
                reason: 'warehouse_inbound_sku_review',
            );
        });
    }
}
