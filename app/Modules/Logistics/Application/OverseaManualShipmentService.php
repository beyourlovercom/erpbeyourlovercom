<?php

namespace App\Modules\Logistics\Application;

use App\Modules\Logistics\Infrastructure\Models\OverseaWarehouseReview;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final class OverseaManualShipmentService
{
    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly ShipmentWebsiteSyncHandoff $syncHandoff,
    ) {}

    /** Only the manual non-OMS warehouses use the shared explicit shipment flow. */
    public function confirmNonOms(Order $order, string $trackingNumber, Authenticatable $actor): array
    {
        $trackingNumber = trim($trackingNumber);
        abort_unless(preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/+\-]*\z/D', $trackingNumber) === 1
            && strlen($trackingNumber) <= 128, 422, '请填写有效的真实 Tracking。');
        $order->refresh()->load(['shipment.warehouse', 'shipment.overseaReview', 'externalReferences']);
        $shipment = $order->shipment;
        $code = OverseaWarehouseReviewService::canonicalWarehouseCode($shipment?->warehouse?->code);
        abort_unless(in_array($code, ['US-NY', 'DE', 'CA'], true), 409,
            'MorePlus 和世迈必须使用专属仓库出库确认流程。');
        abort_unless($order->order_status?->value === 'pending_shipment'
            && ! $order->isOperationallyArchived() && ! $order->trashed() && $order->archived_at === null
            && $shipment?->shipment_status === 'pending' && $shipment->shipped_at === null, 409,
            '订单不在允许确认发货的待发货状态。');
        abort_if($order->fulfillmentChildren()->exists()
            || $order->externalReferences->contains('provider', 'historical_logistics'), 409,
            '已拆分或历史物流订单不能直接确认发货。');
        abort_unless($shipment->overseaReview?->status === 'approved'
            && (int) $shipment->overseaReview->reviewed_warehouse_id === (int) $shipment->warehouse_id,
            409, '当前海外仓尚未审核通过。');
        $carrier = trim((string) $shipment->carrier);
        $service = trim((string) $shipment->service);
        abort_unless($carrier !== '' && $service !== '', 422, '请先确认有效的物流商与服务渠道。');
        abort_unless(app(CarrierServiceCatalog::class)->activeServices($carrier)
            ->contains('service_value', $service), 422, '物流商或服务渠道无效。');
        abort_if(Shipment::query()
            ->where('tracking_number', $trackingNumber)->where('order_id', '!=', $order->id)->exists(),
            409, 'Tracking 已关联其他订单。');

        $shipped = DB::transaction(function () use ($order, $shipment, $carrier, $service, $trackingNumber, $actor): Order {
            $updated = $this->shipments->markShipped($order, [
                'warehouse_id' => $shipment->warehouse_id,
                'carrier' => $carrier,
                'service' => $service,
                'tracking_number' => $trackingNumber,
                'shipped_at' => now()->utc()->toIso8601String(),
                'logistics_note' => $shipment->logistics_note,
            ], $actor);
            OverseaWarehouseReview::query()->where('order_id', $order->id)
                ->where('status', 'approved')->where('reviewed_warehouse_id', $shipment->warehouse_id)
                ->update(['progress' => 'shipped']);

            return $updated->fresh(['shipment.overseaReview', 'items', 'externalReferences']);
        });
        $sync = $this->syncHandoff->afterShipmentSaved($shipped);

        return ['order' => $shipped, 'sync' => $sync];
    }

    /**
     * @return array{order:Order,sync:array<string,mixed>}
     */
    public function confirmUsSm(Order $order, string $trackingNumber, ?Authenticatable $actor): array
    {
        $trackingNumber = trim($trackingNumber);
        abort_if($trackingNumber === '', 422, '世迈发货必须填写真实 Tracking。');

        $order->loadMissing(['shipment.warehouse', 'shipment.overseaReview']);
        $shipment = $order->shipment;
        abort_unless($shipment, 422, '订单没有 Shipment。');

        $code = OverseaWarehouseReviewService::canonicalWarehouseCode($shipment->warehouse?->code);
        abort_unless($code === 'US-SM', 422, '仅 US-SM / 世迈允许使用逐单确认发货。');
        abort_unless($shipment->shipment_status === 'pending', 409, '只有待发货 Shipment 可以逐单确认发货。');

        $review = $shipment->overseaReview;
        abort_unless(
            $review?->status === 'approved'
                && (int) $review->reviewed_warehouse_id === (int) $shipment->warehouse_id,
            409,
            '订单尚未完成当前仓库审核。',
        );

        // This is an ERP shipment confirmation, not an OMS operation.
        // Historical OMS attempts are audited separately and never gate this local action.

        $carrier = trim((string) $shipment->carrier);
        abort_if($carrier === '', 422, '世迈发货缺少 Carrier。');

        $shipped = DB::transaction(function () use ($order, $shipment, $trackingNumber, $carrier, $actor): Order {
            $updated = $this->shipments->markShipped($order, [
                'warehouse_id' => $shipment->warehouse_id,
                'carrier' => $carrier,
                'service' => $shipment->service,
                'tracking_number' => $trackingNumber,
                'shipped_at' => now()->utc()->toIso8601String(),
                'logistics_note' => $shipment->logistics_note,
            ], $actor);

            OverseaWarehouseReview::query()
                ->where('order_id', $order->id)
                ->where('status', 'approved')
                ->where('reviewed_warehouse_id', $shipment->warehouse_id)
                ->update(['progress' => 'shipped']);

            return $updated->fresh([
                'shipment.overseaReview',
                'items',
                'externalReferences',
                'fulfillmentParent.externalReferences',
            ]);
        });

        // Shipment persistence is complete. Sync OS owns remote Tracking + order status.
        $sync = $this->syncHandoff->afterShipmentSaved($shipped);

        return [
            'order' => $shipped->fresh(['shipment.overseaReview']),
            'sync' => $sync,
        ];
    }
}
