<?php

namespace App\Modules\Orders\Application;

use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use App\Modules\Inventory\Infrastructure\Models\InventoryReservation;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Products\Infrastructure\Models\Product;

final class OrderItemAmendmentPreflight
{
    public function __construct(private readonly InventoryAvailabilityReader $availability) {}

    /** @param array<int, array<string, mixed>> $items */
    public function preview(Order $order, array $items): array
    {
        $old = $order->items()->orderBy('id')->get();
        $oldById = $old->keyBy('id');
        $products = Product::query()->whereIn('id', array_column($items, 'product_id'))->get(['id', 'sku'])->keyBy('id');
        $issues = [];
        $seen = [];
        $rows = [];
        $oldCents = 0;
        $newCents = 0;
        foreach ($old as $item) {
            $oldCents += (int) round((float) $item->unit_price * 100) * (int) $item->quantity;
        }
        foreach ($items as $item) {
            $id = isset($item['id']) ? (int) $item['id'] : null;
            if ($id !== null && (! $oldById->has($id) || in_array($id, $seen, true))) {
                $issues[] = '商品行 ID 无效或重复，请重新加载订单后再试。';
            }
            if ($id !== null) {
                $seen[] = $id;
            }
            $product = $products->get((int) $item['product_id']);
            $sku = trim((string) ($product?->sku ?? ''));
            if ($sku === '') {
                $issues[] = '商品缺少有效 SKU，请重新选择产品。';
            }
            $quantity = (int) $item['quantity'];
            $newCents += (int) round((float) $item['unit_price'] * 100) * $quantity;
            $before = $id !== null ? $oldById->get($id) : null;
            $same = $before
                && (int) $before->product_id === (int) $item['product_id']
                && (int) $before->quantity === $quantity
                && (int) round((float) $before->unit_price * 100) === (int) round((float) $item['unit_price'] * 100)
                && (string) ($before->product_summary ?? '') === (string) ($item['product_summary'] ?? '');
            $rows[] = [
                'order_item_id' => $id,
                'operation' => $same ? 'unchanged' : ($before ? 'replace' : 'add'),
                'before' => $before ? ['sku' => $before->sku, 'quantity' => (int) $before->quantity] : null,
                'after' => ['sku' => $sku, 'quantity' => $quantity],
            ];
        }
        foreach ($old as $item) {
            if (! in_array((int) $item->id, $seen, true)) {
                $rows[] = ['order_item_id' => (int) $item->id, 'operation' => 'remove',
                    'before' => ['sku' => $item->sku, 'quantity' => (int) $item->quantity], 'after' => null];
            }
        }
        $changed = collect($rows)->contains(fn (array $row): bool => $row['operation'] !== 'unchanged');
        $isWoo = $order->source_type === OrderSourceType::WooCommerce;
        if ($changed && $isWoo && $oldCents !== $newCents) {
            $issues[] = 'Woo 原订单金额必须保持不变：请把拆分后各商品单价调整为原商品金额总和，不改变客户付款。';
        }
        $reserved = InventoryReservation::query()
            ->where('order_id', $order->id)->whereIn('status', ['reserved', 'committed'])->exists();
        $hasLogistics = $order->shipment()->exists()
            || $order->fulfillmentChildren()->exists()
            || $order->fulfillmentAllocation()->exists()
            || $reserved;
        if ($changed && $hasLogistics) {
            $issues[] = '订单已有分仓、发货或库存预留记录，不能直接覆盖商品；需先通过受控库存重规划处理。';
        }
        if ($changed && $order->isOperationallyArchived()) {
            $issues[] = '订单已归档，不能修改商品。';
        }
        $warehouses = [];
        $stockNote = '库存按仓库当前快照显示；预检不占用、不释放任何库存。';
        if ($changed && $issues === []) {
            $availability = $this->availability->forProductIds(array_values(array_unique(array_map('intval', array_column($items, 'product_id')))));
            foreach ($this->availability->activeWarehouses() as $warehouse) {
                $details = [];
                foreach ($items as $item) {
                    $productId = (int) $item['product_id'];
                    $stock = collect($availability[$productId] ?? [])->firstWhere('warehouse_id', (int) $warehouse['id']);
                    $available = $stock['quantity_available'] ?? null;
                    $details[] = [
                        'product_id' => $productId, 'required' => (int) $item['quantity'],
                        'available' => $available, 'enough' => $available !== null && $available >= (int) $item['quantity'],
                    ];
                }
                $warehouses[] = [
                    'warehouse_id' => (int) $warehouse['id'], 'warehouse_name' => $warehouse['name'],
                    'warehouse_code' => $warehouse['code'],
                    'single_warehouse_candidate' => collect($details)->every(fn (array $row): bool => $row['enough']),
                    'details' => $details,
                ];
            }
        }

        return [
            'changed' => $changed, 'fulfillment_only' => $isWoo,
            'can_save_direct' => $issues === [], 'requires_replan' => $changed && $hasLogistics,
            'can_atomic_single_replan' => false, 'can_atomic_multi_replan' => false,
            'expected_revision' => hash('sha256', $old->map(fn ($item) => $item->only(['id', 'product_id', 'quantity', 'unit_price', 'product_summary']))->toJson()),
            'no_writes' => true, 'currency' => $order->currency ?? 'USD',
            'old_items_gross' => number_format($oldCents / 100, 2, '.', ''),
            'new_items_gross' => number_format($newCents / 100, 2, '.', ''),
            'gross_delta' => number_format(($newCents - $oldCents) / 100, 2, '.', ''),
            'rows' => $rows, 'issues' => array_values(array_unique($issues)),
            'stock_by_warehouse' => $warehouses, 'inventory_note' => $stockNote,
        ];
    }
}
