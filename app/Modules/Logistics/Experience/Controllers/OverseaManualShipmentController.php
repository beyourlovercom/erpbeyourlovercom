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

        $message = match ($syncStatus) {
            'completed' => 'ERP 已发货；Tracking 已同步到官网 AfterShip。',
            'processing', 'retry_scheduled', 'retry_wait' =>
                'ERP 已发货；AfterShip 同步正在处理或等待重试，请到已发货列表查看物流同步状态。',
            'conflict' => 'ERP 已发货；官网已有冲突的物流单号，AfterShip 未更新，请核对后重试。',
            default => 'ERP 已发货；AfterShip 同步失败，请到已发货列表核对物流信息并重试。',
        };

        return redirect('/logistics/oversea/us-3sm?view=shipped')
            ->with($syncStatus === 'completed' ? 'success' : 'warning', $message);
    }
}
