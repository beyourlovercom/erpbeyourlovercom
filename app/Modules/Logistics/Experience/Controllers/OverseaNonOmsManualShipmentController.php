<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Logistics\Application\OverseaManualShipmentService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class OverseaNonOmsManualShipmentController extends Controller
{
    public function __invoke(Request $request, Order $order, OverseaManualShipmentService $service): RedirectResponse
    {
        $data = $request->validate([
            'tracking_number' => ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:\/+\-]*\z/'],
        ]);
        $result = $service->confirmNonOms($order, $data['tracking_number'], $request->user());

        return back()->with(data_get($result, 'sync.status') === 'handoff_failed' ? 'warning' : 'success',
            'ERP 已确认真实发货，官网物流由同步模块处理；请检查后续同步回执。');
    }
}
