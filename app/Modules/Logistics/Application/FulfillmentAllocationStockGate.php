<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Inventory\Application\Contracts\InventoryAvailabilityReader;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Exceptions\HttpResponseException;

final class FulfillmentAllocationStockGate
{
    public function __construct(private readonly InventoryAvailabilityReader $availability) {}

    /**
     * This is an acknowledgement gate, NOT a stock reservation or shipping gate.
     * @param list<array<string,mixed>> $assignments
     * @return list<array<string,mixed>>
     */
    public function warnings(Order $order, array $assignments): array
    {
        $order->loadMissing('items');
        $warehouseByCode = collect($this->availability->activeWarehouses())
            ->keyBy(fn (array $warehouse): string => strtoupper(trim((string) $warehouse['code'])));
        $items = $order->items->keyBy('id');
        $groups = [];

        foreach ($assignments as $assignment) {
            $item = $items->get((int) ($assignment['order_item_id'] ?? 0));
            if (! $item || strcasecmp(trim((string) $item->sku), 'gift') === 0) {
                // Gift mappings have their own hard SKU and stock eligibility gate.
                continue;
            }
            $code = strtoupper(trim((string) ($assignment['source_code'] ?? '')));
            $productId = (int) ($item->product_id ?? 0);
            $key = $code.':'.($productId > 0 ? $productId : 'sku:'.($item->sku ?? $item->id));
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'warehouse_code' => $code,
                    'warehouse_name' => $warehouseByCode->get($code)['name'] ?? $code,
                    'warehouse_id' => (int) ($warehouseByCode->get($code)['id'] ?? 0),
                    'product_id' => $productId,
                    'sku' => (string) ($item->sku ?? '未绑定 SKU'),
                    'required_quantity' => 0,
                ];
            }
            $groups[$key]['required_quantity'] += (int) $item->quantity;
        }

        $availableByProduct = $this->availability->forProductIds(
            array_values(array_unique(array_filter(array_column($groups, 'product_id'))))
        );
        $warnings = [];
        foreach ($groups as $group) {
            $rows = $availableByProduct[$group['product_id']] ?? [];
            $match = collect($rows)->firstWhere('warehouse_id', $group['warehouse_id']);
            $available = $match['quantity_available'] ?? null;
            if ($available !== null && (int) $available >= $group['required_quantity']) {
                continue;
            }
            $warnings[] = [
                'warehouse_code' => $group['warehouse_code'],
                'warehouse_name' => $group['warehouse_name'],
                'sku' => $group['sku'],
                'required_quantity' => $group['required_quantity'],
                'available_quantity' => $available === null ? null : (int) $available,
                'reason' => $available === null ? 'unknown' : 'insufficient',
            ];
        }
        return $warnings;
    }

    /** @param list<array<string,mixed>> $assignments
     * @return list<array<string,mixed>>
     */
    public function assertAcknowledged(Order $order, array $assignments, bool $acknowledged): array
    {
        $warnings = $this->warnings($order, $assignments);
        if ($warnings !== [] && ! $acknowledged) {
            $message = '所选仓库存在库存不足或库存未知的 SKU，请先确认缺货分仓提醒后再提交。';
            if (request()->expectsJson()) {
                throw new HttpResponseException(response()->json([
                    'message' => $message,
                    'errors' => ['stock_exception_acknowledged' => [$message]],
                ], 422));
            }
            throw ValidationException::withMessages(['stock_exception_acknowledged' => $message]);
        }
        return $warnings;
    }
}
