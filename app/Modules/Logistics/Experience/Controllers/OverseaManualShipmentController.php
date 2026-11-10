<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Logistics\Application\OverseaManualShipmentService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class OverseaManualShipmentController extends Controller
{
    public function __invoke(Request $request, Order $order, OverseaManualShipmentService $service): RedirectResponse
    {
        $data = $request->validate([
            'tracking_number' => ['required', 'string', 'max:128'],
        ]);

        $result = $service->confirmUsSm($order, $data['tracking_number'], $request->user());
        $syncStatus = (string) data_get($result, 'sync.status', 'attention');

        $message = $syncStatus === 'handoff_failed'
            ? 'ERP 已发货，但待同步任务登记失败；请管理员在同步模块核对，勿再次确认发货。'
            : 'ERP 已保存发货状态和 Tracking；官网由同步模块每 30 分钟处理，最终状态以回执为准。';

        return redirect('/logistics/oversea/us-3sm?view=shipped')
            ->with($syncStatus === 'handoff_failed' ? 'warning' : 'success', $message);
    }
}
