<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Inventory\Infrastructure\Models\OverdueInventoryExclusion;
use App\Modules\Products\Infrastructure\Models\Product;
use Illuminate\Support\Facades\DB;

final class OverdueInventoryExclusionService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function set(Product $product, bool $excluded, ?string $reason, User $actor): void
    {
        DB::transaction(function () use ($product, $excluded, $reason, $actor): void {
            // Lock stable product identity, not a mutable SKU or a warehouse lot.
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $record = OverdueInventoryExclusion::query()
                ->where('product_id', $product->id)->lockForUpdate()->first();
            $before = $record ? [
                'is_excluded' => (bool) $record->is_excluded,
                'reason' => $record->reason,
            ] : ['is_excluded' => false, 'reason' => null];

            $after = [
                'is_excluded' => $excluded,
                'reason' => $excluded ? $reason : null,
            ];
            if ($before === $after) {
                return; // idempotent toggle: no duplicate audit records
            }

            OverdueInventoryExclusion::query()->updateOrCreate(
                ['product_id' => $product->id],
                [...$after, 'updated_by_user_id' => $actor->id],
            );
            $this->audit->record(
                AuditActor::user($actor, AuditSource::Web),
                $excluded ? 'inventory.overdue.product_excluded' : 'inventory.overdue.product_restored',
                Product::class,
                $product->id,
                before: ['product_sku' => $product->sku, ...$before],
                after: ['product_sku' => $product->sku, ...$after],
                reason: 'overdue_report_product_scope_only',
            );
        });
    }
}
