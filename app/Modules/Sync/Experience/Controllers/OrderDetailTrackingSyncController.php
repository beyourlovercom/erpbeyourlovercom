<?php

namespace App\Modules\Sync\Experience\Controllers;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Infrastructure\WooCommerce\HttpWooCommerceTrackingGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class OrderDetailTrackingSyncController
{
    public function push(Request $request, Order $order, HttpWooCommerceTrackingGateway $gateway): JsonResponse
    {
        $order->loadMissing(['shipment', 'fulfillmentParent.externalReferences', 'externalReferences']);
        if ($order->trashed() || $order->isOperationallyArchived() || $order->archived_at !== null) {
            return response()->json(['message' => '订单已归档，请先恢复。'], 422);
        }
        $number = trim((string) $order->shipment?->tracking_number);
        if ($number === '') {
            return response()->json(['message' => '请先填写 ERP Tracking Number。'], 422);
        }
        $root = $order->fulfillment_parent_id ? $order->fulfillmentParent : $order;
        $source = $root?->source_type instanceof \BackedEnum
            ? $root->source_type->value : (string) $root?->source_type;
        if ($source !== 'woocommerce') {
            return response()->json(['message' => '订单未关联 WooCommerce 官网。'], 422);
        }
        $descriptor = $gateway->descriptor();
        $reference = $root->externalReferences->first(fn ($item) => $item->provider === 'woocommerce'
            && $item->connection_key === ($descriptor['connection_key'] ?? null)
            && ctype_digit((string) $item->reference));
        if (! $reference) {
            return response()->json(['message' => '缺少可信的 WooCommerce 订单引用。'], 422);
        }
        $carrier = (string) $order->shipment?->carrier;
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', $carrier)) ?? $carrier));
        $slug = 'other';
        foreach ((array) config('erp_logistics.carriers', []) as $definition) {
            foreach ([...(array) ($definition['aliases'] ?? []), (string) ($definition['value'] ?? '')] as $alias) {
                $value = strtolower(trim(preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', (string) $alias)) ?? (string) $alias));
                if ($normalized !== '' && $value === $normalized) {
                    $slug = (string) $definition['aftership_slug'];
                    break 2;
                }
            }
        }
        try {
            $result = $gateway->forceSyncSingleTracking(
                (int) $reference->reference,
                $slug,
                $number,
                ($order->shipment?->shipped_at ?? now())->utc()->format('Y-m-d')
            );
            Log::info('erp.order_detail_tracking_sync', [
                'erp_order_id' => $order->id, 'woo_order_id' => (int) $reference->reference,
                'actor_id' => $request->user()?->getAuthIdentifier(),
                'tracking_number' => $number, 'written' => $result['written'],
            ]);
            return response()->json([
                'message' => $result['written'] ? 'Tracking 已更新并通过官网回读验证。' : '网站 Tracking 已是最新，无需修改。',
                'written' => $result['written'],
                'verified' => $result['verified'],
            ]);
        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['message' => '同步失败：'.$exception->getMessage()], 422);
        }
    }
}
