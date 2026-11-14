<?php

namespace App\Modules\Sync\Experience\Controllers;

use App\Modules\Orders\Infrastructure\Models\Order;
use App\Modules\Sync\Application\SyncOutboxWriteException;
use App\Modules\Sync\Application\WooCommerceTrackingOutboundService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class WooCommerceTrackingOutboundController
{
    public function __construct(private readonly WooCommerceTrackingOutboundService $tracking) {}

    public function push(Request $request, Order $order): RedirectResponse
    {
        try {
            $result = $this->tracking->pushOne($order, $request->user(), verifyRemote: true, allowLegacyTrackingContract: true);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (SyncOutboxWriteException $exception) {
            Log::warning('woo.tracking.manual_outbox_conflict', [
                'order_id' => (int) $order->getKey(),
                'actor_id' => $request->user()?->getAuthIdentifier(),
                'exception' => $exception->getMessage(),
            ]);

            return back()->with('error', '已有内容不同的同步任务，系统未覆盖历史任务或官网物流。请联系管理员核对。');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', '官网物流同步未完成，请稍后重试或联系管理员核对任务记录。');
        }

        return match ($result['status']) {
            'completed' => back()->with('success', 'WooCommerce Tracking 已写回并验证'),
            'processing' => back()->with('info', 'WooCommerce Tracking 正在处理'),
            'retry_wait' => back()->with('warning', 'WooCommerce Tracking 正在等待下一次重试时间'),
            'retry_scheduled' => back()->with('warning', 'WooCommerce Tracking 写回失败，已进入重试队列'),
            'conflict' => back()->with('error', 'WooCommerce 已存在不同 Tracking，ERP 未覆盖远端数据'),
            default => back()->with('error', 'WooCommerce Tracking 写回失败'),
        };
    }
}
