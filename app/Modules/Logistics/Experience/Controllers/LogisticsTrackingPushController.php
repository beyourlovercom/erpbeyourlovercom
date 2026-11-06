<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Logistics\Application\LogisticsTrackingPushAccess;
use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\SyncOrchestrationService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'manual_override' => ['sometimes', 'boolean'],
        ]);
        $ids = array_map('intval', $data['order_ids']);
        $manualOverride = (bool) ($data['manual_override'] ?? false);
        abort_if($manualOverride && count($ids) !== 1, 422, '人工强制同步只能逐笔执行，批量同步仍走原安全规则。');
        if ((string) config('tracking_sync_v2.manual_mode') === 'canary') {
            // Fail closed even if manual Canary mode was enabled without any IDs.
            $allowed = array_values(array_unique(array_map(
                'intval',
                array_filter(explode(',', (string) config('tracking_sync_v2.manual_canary_order_ids', '')),
                    fn (string $value) => ctype_digit(trim($value)) && (int) trim($value) > 0),
            )));
            abort_unless($allowed !== [] && array_diff($ids, $allowed) === [], 403);
        }
        $orders = Order::query()->with(['shipment', 'fulfillmentParent'])
            ->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $id) {
            $order = $orders->get($id);
            abort_unless($order, 422, '所选订单已不存在，请刷新列表');
            $root = $order->fulfillment_parent_id ? $order->fulfillmentParent : $order;
            $source = $root?->source_type instanceof \BackedEnum
                ? $root->source_type->value : (string) ($root?->source_type ?? '');
            if (! $manualOverride) {
                abort_unless($source === 'woocommerce', 422, '只允许同步 WooCommerce 来源订单');
            }
            $shipment = $order->shipment;
            if ($manualOverride) {
                abort_unless($shipment && filled($shipment->tracking_number), 422, '至少需要填写真实 Tracking 才能同步官网');
            } else {
                abort_unless(
                    $shipment?->shipment_status === 'shipped'
                    && filled($shipment->carrier)
                    && filled($shipment->tracking_number)
                    && $shipment->shipped_at !== null,
                    422,
                    '必须先完成发货并填写承运商、Tracking 和发货时间',
                );
            }
        }

        try {
            $run = $this->orchestration->trackingSelected($ids, $request->user(), manualOverride: $manualOverride);
            $problem = DB::table('sync_run_items')
                ->where('sync_run_id', $run->getKey())
                ->whereIn('status', ['skipped', 'failed', 'conflict', 'retrying'])
                ->orderBy('id')->first(['error_message', 'error_code', 'result_code']);
            $problemText = trim((string) ($problem?->error_message ?: $problem?->error_code ?: ''));

            return back()->with(
                $run->failed_items || $run->conflict_items || $run->retrying_items || $run->skipped_items
                    ? 'warning' : 'success',
                sprintf(
                    '官网物流同步：成功 %d，待重试 %d，冲突 %d，失败 %d，跳过 %d。%s',
                    (int) $run->succeeded_items,
                    (int) $run->retrying_items,
                    (int) $run->conflict_items,
                    (int) $run->failed_items,
                    (int) $run->skipped_items,
                    $problemText !== '' ? '原因：'.mb_strimwidth($problemText, 0, 280, '…') : '',
                ),
            );
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable) {
            return back()->with('error', '官网物流同步未完成，请核对同步记录后再重试');
        }
    }
}
