<?php

namespace App\Modules\Orders\Application;

use App\Modules\Audit\Application\AuditActor;
use App\Modules\Audit\Application\AuditSource;
use App\Modules\Audit\Application\AuditService;
use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsDispatchAttempt;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Orders\Infrastructure\Models\OrderItem;
use App\Modules\Products\Infrastructure\Models\Product;
use App\Modules\Products\Infrastructure\Models\ProductSkuAlias;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Exact one-line SKU correction; intentionally avoids OrderService::update()
 * which deletes and recreates Woo line items / source references.
 */
final class OrderLineSkuCorrectionService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @return array{order_id:int,item_id:int,sku:string,product_id:int} */
    public function correct(
        int $orderId,
        int $itemId,
        string $expectedSku,
        string $targetSku,
        Authenticatable $actor
    ): array {
        return DB::transaction(function () use ($orderId, $itemId, $expectedSku, $targetSku, $actor): array {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            if ($order->order_status?->value !== 'pending_shipment'
                || $order->shipment()->exists()
                || $order->fulfillmentChildren()->exists()
                || $order->fulfillmentAllocation()->exists()
                || XlwmsDispatchAttempt::query()->where('order_id', $orderId)->exists()) {
                throw new HttpException(409, '该订单已进入发货流程，不允许修改 SKU');
            }

            $line = $order->items()->whereKey($itemId)->lockForUpdate()->firstOrFail();
            if (trim((string) $line->sku) !== $expectedSku || $targetSku === '') {
                throw new HttpException(409, '订单行已变化，请刷新后重试');
            }
            $product = Product::query()->where('sku', $targetSku)->first();
            if (! $product) {
                $aliases = ProductSkuAlias::query()->where('alias_sku', $targetSku)->get();
                if ($aliases->count() !== 1) {
                    throw new HttpException(422, '该 SKU 尚未建立唯一商品或 SKU 别名，不能猜测商品身份');
                }
                $product = Product::query()->findOrFail($aliases->first()->product_id);
            }

            $before = $line->only(['id', 'order_id', 'product_id', 'sku', 'source_sku', 'resolved_fulfillment_sku', 'quantity', 'unit_price', 'woocommerce_line_item_id']);
            if (! filled($line->source_sku)) $line->source_sku = $line->sku;
            $line->product_id = $product->id;
            $line->sku = $targetSku; // exact Owner-verified spreadsheet SKU, not alias canonical
            $line->resolved_fulfillment_sku = $targetSku;
            $line->save();

            $after = $line->fresh()->only(array_keys($before));
            $this->audit->record(
                AuditActor::user($actor, AuditSource::InternalSystem),
                'order.item.fulfillment_sku_corrected',
                OrderItem::class,
                (int) $line->id,
                before: $before,
                after: $after,
                reason: 'tencent_shipping_sheet_verified'
            );

            return ['order_id' => $orderId, 'item_id' => $itemId,
                'sku' => $targetSku, 'product_id' => (int) $product->id];
        });
    }
}
