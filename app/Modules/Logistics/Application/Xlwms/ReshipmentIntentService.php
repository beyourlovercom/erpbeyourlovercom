<?php

namespace App\Modules\Logistics\Application\Xlwms;

use App\Modules\Logistics\Infrastructure\Xlwms\XlwmsReshipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Persists a separately identified operator request. This service does not
 * invoke OMS or mutate the original shipment / Woo order.
 */
final class ReshipmentIntentService
{
    public function __construct(private readonly ReshipmentPreview $preview) {}

    /** @return array<string, mixed> */
    public function request(int $orderId, int $actorId, string $reason): array
    {
        $reason = trim($reason);
        if ($actorId < 1 || mb_strlen($reason) < 5 || mb_strlen($reason) > 180) {
            throw new RuntimeException('RESHIP_INTENT_INPUT_INVALID');
        }

        return DB::transaction(function () use ($orderId, $actorId, $reason): array {
            // Serialize all intents for the same original order.
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $preview = $this->preview->forOrder((int) $order->id);
            if (! $preview['ready_for_review']) {
                throw new RuntimeException('RESHIP_PREFLIGHT_BLOCKED');
            }
            $shipment = $order->shipment;
            if (! $shipment) {
                throw new RuntimeException('RESHIP_SHIPMENT_MISSING');
            }

            $uuid = (string) Str::uuid();
            $third = 'BYL-RESHIP-'.str_replace('-', '', $uuid);
            $items = $preview['items'];
            $fingerprint = hash('sha256', json_encode([
                'order_id' => $orderId,
                'shipment_id' => $shipment->id,
                'items' => $items,
                'recipient' => $preview['recipient'],
                'address' => $preview['address'],
                'reason' => $reason,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $record = XlwmsReshipment::query()->create([
                'original_order_id' => $orderId,
                'original_shipment_id' => $shipment->id,
                'warehouse_id' => $shipment->warehouse_id,
                'request_id' => $uuid,
                'third_order_no' => $third,
                'original_oms_order_no' => $preview['original_oms_order_no'],
                'status' => 'requested',
                'reason' => $reason,
                'memo' => '重新下发仓库',
                'payload_fingerprint' => $fingerprint,
                'sku_quantity_snapshot' => $items,
                'requested_by' => $actorId,
            ]);
            DB::table('xlwms_reshipment_events')->insert([
                'reshipment_id' => $record->id,
                'previous_status' => null,
                'status' => 'requested',
                'event_code' => 'operator_requested',
                'actor_id' => $actorId,
                'created_at' => now(),
            ]);
            return [
                'request_id' => $uuid,
                'status' => 'requested',
                'can_create_oms' => false,
                'memo' => '重新下发仓库',
            ];
        });
    }
}
