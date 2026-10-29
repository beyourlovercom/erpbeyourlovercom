<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Logistics\Application\LogisticsTrackingPushAccess;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\SyncOrchestrationService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

final class LogisticsTrackingPushController
{
    public function __construct(
        private readonly LogisticsTrackingPushAccess $access,
        private readonly SyncOrchestrationService $orchestration,
    ) {}

    public function selected(Request $request): RedirectResponse
    {
        abort_unless($this->access->allows($request->user()), 403);

        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:50'],
            'order_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $ids = array_map('intval', $data['order_ids']);
        $orders = Order::query()->with(['shipment', 'fulfillmentParent'])
            ->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $id) {
            $order = $orders->get($id);
            abort_unless($order, 422, '所选订单已不存在，请刷新列表');
            $root = $order->fulfillment_parent_id ? $order->fulfillmentParent : $order;
            $source = $root?->source_type instanceof \BackedEnum
                ? $root->source_type->value : (string) ($root?->source_type ?? '');
            abort_unless($source === 'woocommerce', 422, '只允许同步 WooCommerce 来源订单');
            $shipment = $order->shipment;
            abort_unless(
                $shipment?->shipment_status === 'shipped'
                && filled($shipment->carrier)
                && filled($shipment->tracking_number)
                && $shipment->shipped_at !== null,
                422,
                '必须先完成发货并填写承运商、Tracking 和发货时间',
            );
        }

        try {
            $run = $this->orchestration->trackingSelected($ids, $request->user());
            return back()->with(
                $run->failed_items || $run->conflict_items || $run->retrying_items
                    ? 'warning' : 'success',
                sprintf(
                    '官网物流同步：成功 %d，待重试 %d，冲突 %d，失败 %d。详情可在同步记录查看。',
                    (int) $run->succeeded_items,
                    (int) $run->retrying_items,
                    (int) $run->conflict_items,
                    (int) $run->failed_items,
                ),
            );
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable) {
            return back()->with('error', '官网物流同步未完成，请核对同步记录后再重试');
        }
    }
}
