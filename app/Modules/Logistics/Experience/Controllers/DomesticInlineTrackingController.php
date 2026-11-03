<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Inventory\Infrastructure\Models\Warehouse;
use App\Modules\Logistics\Application\AssistedFulfillmentService;
use App\Modules\Logistics\Application\LogisticsTrackingImmediateDispatch;
use App\Modules\Logistics\Application\ShipmentService;
use App\Modules\Logistics\Infrastructure\Models\Shipment;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class DomesticInlineTrackingController
{
    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly AssistedFulfillmentService $assistedFulfillment,
        private readonly LogisticsTrackingImmediateDispatch $immediateDispatch,
    ) {}

    /** Persist a Tracking draft only; never mark shipped or contact a provider/Woo. */
    public function save(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'tracking_number' => ['nullable', 'string', 'max:128', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:\/+\-]*\z/'],
        ], ['tracking_number.regex' => '发货单号不能包含空格、中文或备注，请只输入单号本体。']);
        $this->assertApprovedFactoryOrder($order);
        $tracking = trim((string) ($data['tracking_number'] ?? ''));
        $this->assertNotDuplicate($order, $tracking);
        $this->shipments->saveDraft($order, ['tracking_number' => $tracking ?: null], $request->user());

        return back()->with('success', '发货单号草稿已保存，尚未确认发货。');
    }

    /** Explicit action after actual physical dispatch; reuses the canonical shipping gate. */
    public function ship(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'tracking_number' => ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:\/+\-]*\z/'],
        ], ['tracking_number.regex' => '发货单号不能包含空格、中文或备注，请只输入单号本体。']);
        $shipment = $this->assertApprovedFactoryOrder($order);
        $tracking = trim($data['tracking_number']);
        $this->assertNotDuplicate($order, $tracking);
        abort_unless(filled($shipment->carrier), 422, '请先选择国内物流商，再确认发货。');

        $shipped = $this->assistedFulfillment->markShipped($order, [
            'warehouse_id' => (int) $shipment->warehouse_id,
            'carrier' => (string) $shipment->carrier,
            'service' => (string) $shipment->service,
            'tracking_number' => $tracking,
            'shipped_at' => now()->utc()->toIso8601String(),
            'logistics_note' => $shipment->logistics_note,
        ], $request->user());
        $sync = $this->immediateDispatch->afterShipmentSaved($shipped, $request->user());

        return back()->with('success', ($sync['status'] ?? null) === 'completed'
            ? '已确认发货，官网物流同步完成。'
            : '已确认发货，官网物流同步请在列表查看。');
    }

    private function assertApprovedFactoryOrder(Order $order): Shipment
    {
        $order->refresh()->load(['shipment', 'operationalMarkers', 'externalReferences']);
        $shipment = $order->shipment;
        $factoryId = Warehouse::query()->whereRaw('UPPER(code) = ?', ['FACTORY'])->where('is_active', true)->value('id');

        abort_unless($shipment && $factoryId && (int) $shipment->warehouse_id === (int) $factoryId, 409, '订单未分配到中国工厂仓。');
        abort_if($order->archived_at !== null || $order->order_status?->value === 'cancelled', 409, '已归档或取消的订单不能操作。');
        abort_if($order->fulfillmentChildren()->exists(), 409, '已拆分原单不能直接发货，请操作分仓子单。');
        abort_if($order->externalReferences->contains('provider', 'historical_logistics'), 409, '历史物流证据订单不允许快速发货。');

        if ($order->order_status?->value !== 'pending_shipment' || $shipment->shipment_status !== 'pending') {
            throw ValidationException::withMessages(['tracking_number' => '只有尚未发货的订单可以在列表录入发货单号。']);
        }
        if (! $order->operationalMarkers->contains('marker', 'domestic_reviewed')
            || $order->operationalMarkers->contains('marker', 'domestic_stockout')
            || $order->workflow_status === 'out_of_stock') {
            throw ValidationException::withMessages(['tracking_number' => '请先由 Kaven 确认有货；缺货或未通过审核的订单不能录入或确认发货。']);
        }

        return $shipment;
    }

    private function assertNotDuplicate(Order $order, string $tracking): void
    {
        if ($tracking !== '' && Shipment::query()->where('tracking_number', $tracking)
            ->where('order_id', '!=', $order->id)->where('shipment_status', '!=', 'cancelled')->exists()) {
            throw ValidationException::withMessages(['tracking_number' => '该 Tracking 已被其他订单使用，请核对后再保存。']);
        }
    }
}
