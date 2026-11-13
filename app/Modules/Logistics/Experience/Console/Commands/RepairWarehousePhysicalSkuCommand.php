<?php

namespace App\Modules\Logistics\Experience\Console\Commands;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Logistics\Application\OverseaFiles\WarehouseOrderReference;
use App\Modules\Logistics\Application\WarehousePhysicalSkuResolver;
use App\Modules\Logistics\Infrastructure\ShipOut\ShipOutProductionReadOnlyGateway;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-order guarded repair for pre-fix allocation rows (e.g. C42378).
 * Default is preview; never dispatches or syncs WooCommerce.
 */
final class RepairWarehousePhysicalSkuCommand extends Command
{
    protected $signature = 'erp:repair-warehouse-physical-sku
        {order_id : ERP numeric order ID}
        {--apply : Commit verified SKU identity without sending a warehouse order}';

    protected $description = 'Preview or safely repair one pending US-LA fulfillment SKU from its approved allocation';

    public function handle(
        WarehousePhysicalSkuResolver $resolver,
        ShipOutProductionReadOnlyGateway $provider,
        WarehouseOrderReference $refs,
        AuditRecorder $audit,
    ): int {
        $id = (int) $this->argument('order_id');
        if ($id < 1) {
            throw new RuntimeException('ORDER_ID_INVALID');
        }
        $order = Order::query()->with(['items', 'shipment.warehouse', 'shipment.overseaReview', 'fulfillmentAllocation'])->findOrFail($id);
        $this->assertEligible($order);
        $ref = $refs->warehouse($order);
        $record = $provider->orderPage(trim((string) config('shipout.production_warehouse_id')), $ref, 1, 10);
        if (($record['total'] ?? -1) !== 0 || ($record['records'] ?? []) !== []) {
            throw new RuntimeException('PROVIDER_ORDER_EXISTS_NO_SKU_REPAIR');
        }
        $snapshot = (array) ($order->fulfillmentAllocation?->plan_snapshot ?? []);
        $segment = collect($snapshot['segments'] ?? [])->sole();
        if (($segment['source_code'] ?? '') !== 'US-LA') {
            throw new RuntimeException('ALLOCATION_WAREHOUSE_CHANGED');
        }
        $assignments = collect($order->fulfillmentAllocation?->manual_assignments ?? [])->keyBy('order_item_id');
        $planned = collect($segment['items'] ?? [])->keyBy('order_item_id');
        if ($planned->count() !== $order->items->count()) {
            throw new RuntimeException('ALLOCATION_ITEM_COUNT_MISMATCH');
        }
        $changes = [];
        foreach ($order->items as $item) {
            $line = $planned->get($item->id);
            if (! $line) {
                throw new RuntimeException('ALLOCATION_ITEM_ID_MISMATCH');
            }
            $selectedMappingId = $assignments->get($item->id)['gift_mapping_id'] ?? null;
            $resolved = $resolver->resolve($item, 'US-LA', $selectedMappingId ? (int) $selectedMappingId : null);
            if (strcasecmp((string) ($line['resolved_fulfillment_sku'] ?? ''), $resolved['resolved_fulfillment_sku']) !== 0
                || (int) ($line['resolved_product_id'] ?? $resolved['resolved_product_id']) !== $resolved['resolved_product_id']) {
                throw new RuntimeException('ALLOCATION_RESOLUTION_CHANGED');
            }
            $changes[] = [
                'item_id' => $item->id, 'source_sku' => $resolved['source_sku'],
                'source_product_id' => $resolved['source_product_id'],
                'sku' => $resolved['resolved_fulfillment_sku'],
                'product_id' => $resolved['resolved_product_id'],
                'product_summary' => $resolved['resolved_product_summary'] ?? $resolved['source_product_summary'],
                'source_product_summary' => $resolved['source_product_summary'],
            ];
        }
        $this->line(json_encode([
            'order_id' => $id, 'warehouse' => 'US-LA', 'ready' => true,
            'apply' => (bool) $this->option('apply'), 'changes' => $changes,
            'provider_order_count' => 0, 'dispatch_attempt_count' => 0,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if (! $this->option('apply')) {
            return self::SUCCESS;
        }
        DB::transaction(function () use ($id, $changes, $audit): void {
            $locked = Order::query()->with(['items', 'shipment.warehouse', 'shipment.overseaReview'])->lockForUpdate()->findOrFail($id);
            $this->assertEligible($locked);
            foreach ($changes as $change) {
                $item = $locked->items->firstWhere('id', $change['item_id']);
                if (! $item) {
                    throw new RuntimeException('ORDER_ITEM_CHANGED_BEFORE_REPAIR');
                }
                if (strcasecmp((string) ($item->source_sku ?: $item->sku), $change['source_sku']) !== 0
                    || (int) ($item->source_product_id ?: $item->product_id) !== $change['source_product_id']) {
                    throw new RuntimeException('SOURCE_IDENTITY_CHANGED_BEFORE_REPAIR');
                }
                $before = ['sku' => $item->sku, 'product_id' => $item->product_id];
                $item->forceFill([
                    'sku' => $change['sku'],
                    'product_id' => $change['product_id'],
                    'product_summary' => $change['product_summary'],
                    'source_sku' => $change['source_sku'],
                    'source_product_id' => $change['source_product_id'],
                    'source_product_summary' => $change['source_product_summary'],
                    'resolved_fulfillment_sku' => $change['sku'],
                ])->save();
                $audit->record(
                    AuditActor::system(AuditSource::InternalSystem),
                    'order_item.warehouse_physical_sku_repaired',
                    $item::class,
                    (int) $item->getKey(),
                    before: $before,
                    after: ['sku' => $change['sku'], 'product_id' => $change['product_id'],
                        'source_sku' => $change['source_sku'], 'source_product_id' => $change['source_product_id']],
                    reason: 'owner_approved_pending_us_la_physical_sku_repair',
                );
            }
        });
        $this->info('REPAIR_APPLIED_NO_WAREHOUSE_DISPATCH_NO_WOO_SYNC');

        return self::SUCCESS;
    }

    private function assertEligible(Order $order): void
    {
        if ($order->isOperationallyArchived()
            || $order->order_status?->value !== 'pending_shipment'
            || $order->shipment?->shipment_status !== 'pending'
            || filled($order->shipment?->tracking_number)
            || $order->shipment?->shipped_at !== null
            || strtoupper((string) ($order->shipment?->warehouse?->code ?? '')) !== 'US-LA'
            || $order->shipment?->overseaReview?->status !== 'approved'
            || ! $order->fulfillmentAllocation) {
            throw new RuntimeException('ORDER_NOT_PENDING_APPROVED_US_LA');
        }
        if (DB::table('shipout_dispatch_attempts')
            ->where('order_id', $order->id)->exists()) {
            throw new RuntimeException('EXISTING_SHIPOUT_ATTEMPT_NO_REPAIR');
        }
    }
}
