<?php

namespace App\Modules\Logistics\Experience\Console\Commands;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\Contracts\AuditRecorder;
use App\Modules\Logistics\Application\GiftSkuResolutionService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Historical mapping recovery. Never changes shipped orders or calls an OMS. */
final class RepairAllocatedGiftSkuCommand extends Command
{
    protected $signature = 'erp:repair-allocated-gift-skus
        {--order=* : Limit to specified ERP numeric order IDs}
        {--apply : Persist safe repairs; default is read-only preview}';

    protected $description = 'Audit and recover only pending warehouse Gift items with an already selected real SKU';

    public function handle(GiftSkuResolutionService $gifts, AuditRecorder $audit): int
    {
        $ids = array_values(array_filter(array_map('intval', $this->option('order'))));
        $orders = Order::query()->with(['items', 'shipment.warehouse', 'fulfillmentAllocation'])
            ->whereHas('shipment', fn ($q) => $q->where('shipment_status', 'pending'))
            ->whereHas('items', fn ($q) => $q->whereRaw('LOWER(TRIM(sku)) = ?', ['gift']))
            ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('id')->get();
        $previewed = $applied = 0;
        $blocked = [];
        foreach ($orders as $order) {
            try {
                $proposed = $this->proposal($order, $gifts);
                $previewed++;
                $this->line(json_encode(['order_id' => $order->id,
                    'number' => $order->erp_order_id, 'warehouse' => $order->shipment?->warehouse?->code,
                    'from' => 'gift', 'to' => $proposed['sku'],
                    'target_product_id' => $proposed['product_id'], 'apply' => (bool) $this->option('apply'),
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                if (! $this->option('apply')) {
                    continue;
                }
                DB::transaction(function () use ($order, $gifts, $audit, $proposed): void {
                    $locked = Order::query()->with(['items', 'shipment.warehouse', 'fulfillmentAllocation'])
                        ->whereKey($order->id)->lockForUpdate()->firstOrFail();
                    $again = $this->proposal($locked, $gifts);
                    if ($again['sku'] !== $proposed['sku'] || $again['product_id'] !== $proposed['product_id']) {
                        throw new RuntimeException('GIFT_RESOLUTION_CHANGED_REFRESH_REQUIRED');
                    }
                    $this->remapSourceItemIds($locked);
                    $item = $locked->items->firstWhere('id', $again['item_id']);
                    $before = ['sku' => $item->sku, 'product_id' => $item->product_id];
                    $item->forceFill([
                        'source_sku' => $item->source_sku ?: $item->sku,
                        'source_product_id' => $item->source_product_id ?: $item->product_id,
                        'source_product_summary' => $item->source_product_summary ?: $item->product_summary,
                        'sku' => $again['sku'], 'product_id' => $again['product_id'],
                        'product_summary' => $again['summary'],
                        'resolved_fulfillment_sku' => $again['sku'],
                    ])->save();
                    $audit->record(AuditActor::system(AuditSource::InternalSystem),
                        'order_item.selected_gift_sku_restored', $item::class, (int) $item->id,
                        before: $before, after: ['sku' => $again['sku'], 'product_id' => $again['product_id'],
                            'source_sku' => 'gift'],
                        reason: 'approved_allocation_mapping_not_materialized');
                });
                $applied++;
            } catch (Throwable $e) {
                $blocked[] = ['order_id' => $order->id, 'code' => $e->getMessage()];
            }
        }
        $this->line(json_encode(['previewed' => $previewed, 'applied' => $applied,
            'blocked' => $blocked, 'sent_to_warehouse' => false, 'woo_written' => false],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $blocked === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Woo's old delete/recreate replaced *all* line IDs, not only Gift.
     * Correct saved Vita selections by uniquely matching each original line
     * to the current source product/SKU/quantity, never by guessing an alias.
     */
    private function remapSourceItemIds(Order $order): void
    {
        $allocation = $order->fulfillmentAllocation;
        $plan = $allocation->plan_snapshot;
        $segments = $plan['segments'] ?? [];
        if (count($segments) !== 1) {
            throw new RuntimeException('SPLIT_ALLOCATION_REMAP_BLOCKED');
        }
        $oldToNew = [];
        $used = [];
        if (! isset($segments[0]['items'])) {
            throw new RuntimeException('ALLOCATION_ITEMS_MISSING');
        }
        foreach ($segments[0]['items'] as &$planned) {
            $sku = trim((string) ($planned['source_sku'] ?? $planned['sku'] ?? ''));
            $id = (int) ($planned['source_product_id'] ?? $planned['product_id'] ?? 0);
            $qty = (int) ($planned['quantity'] ?? 0);
            $matches = $order->items->filter(fn ($current) => ! isset($used[(int) $current->id])
                && strcasecmp(trim((string) ($current->source_sku ?: $current->sku)), $sku) === 0
                && (int) ($current->source_product_id ?: $current->product_id) === $id
                && (int) $current->quantity === $qty
            )->values();
            if ($matches->count() !== 1) {
                throw new RuntimeException('SOURCE_LINE_IDENTITY_AMBIGUOUS');
            }
            $match = $matches[0];
            $oldToNew[(int) ($planned['order_item_id'] ?? 0)] = (int) $match->id;
            $planned['order_item_id'] = (int) $match->id;
            $used[(int) $match->id] = true;
        }
        unset($planned);
        if (count($used) !== $order->items->count()) {
            throw new RuntimeException('SOURCE_LINE_COUNT_MISMATCH');
        }
        $assignments = $allocation->manual_assignments;
        if ($assignments !== null) {
            foreach ($assignments as &$row) {
                $oldId = (int) ($row['order_item_id'] ?? 0);
                if (! isset($oldToNew[$oldId])) {
                    throw new RuntimeException('MANUAL_ASSIGNMENT_SOURCE_MISMATCH');
                }
                $row['order_item_id'] = $oldToNew[$oldId];
            }
            unset($row);
        }
        $plan['segments'] = $segments;
        $allocation->forceFill(['plan_snapshot' => $plan, 'manual_assignments' => $assignments])->save();
    }

    /** @return array{item_id:int,sku:string,product_id:int,summary:string} */
    private function proposal(Order $order, GiftSkuResolutionService $gifts): array
    {
        $shipment = $order->shipment;
        if ($order->isOperationallyArchived() || $order->order_status?->value !== 'pending_shipment'
            || ! $shipment || $shipment->shipment_status !== 'pending'
            || filled($shipment->tracking_number) || $shipment->shipped_at !== null
            || ! $shipment->warehouse || ! $order->fulfillmentAllocation) {
            throw new RuntimeException('ORDER_ALREADY_FULFILLED_OR_UNALLOCATED');
        }
        $items = $order->items->filter(fn ($item) => $gifts->isGiftSku($item->sku))->values();
        if ($items->count() !== 1) {
            throw new RuntimeException('GIFT_ITEM_NOT_UNIQUE');
        }
        $segments = collect($order->fulfillmentAllocation->plan_snapshot['segments'] ?? []);
        if ($segments->count() !== 1
            || strcasecmp((string) ($segments[0]['source_code'] ?? ''), (string) $shipment->warehouse->code) !== 0) {
            throw new RuntimeException('WAREHOUSE_ALLOCATION_MISMATCH');
        }
        $selected = collect($segments[0]['items'] ?? [])
            ->filter(fn ($line) => $gifts->isGiftSku($line['source_sku'] ?? $line['sku'] ?? null))->values();
        if ($selected->count() !== 1) {
            throw new RuntimeException('GIFT_SELECTION_NOT_UNIQUE');
        }
        $line = $selected[0];
        $item = $items[0];
        if ((int) ($line['quantity'] ?? 0) !== (int) $item->quantity
            || (int) ($line['product_id'] ?? 0) !== (int) ($item->source_product_id ?: $item->product_id)) {
            throw new RuntimeException('GIFT_SOURCE_LINE_CHANGED');
        }
        $resolution = $gifts->resolve($item, (string) $shipment->warehouse->code,
            (int) ($line['gift_mapping_id'] ?? 0));
        if (strcasecmp((string) ($line['resolved_fulfillment_sku'] ?? ''), $resolution['resolved_fulfillment_sku']) !== 0
            || (int) ($line['resolved_product_id'] ?? 0) !== $resolution['resolved_product_id']) {
            throw new RuntimeException('SELECTED_GIFT_MAPPING_CHANGED');
        }

        return ['item_id' => (int) $item->id,
            'sku' => (string) $resolution['resolved_fulfillment_sku'],
            'product_id' => (int) $resolution['resolved_product_id'],
            'summary' => (string) $resolution['resolved_product_summary']];
    }
}
