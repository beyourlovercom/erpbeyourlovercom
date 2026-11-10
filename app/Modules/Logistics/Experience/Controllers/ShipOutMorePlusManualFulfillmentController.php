<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Logistics\Application\ShipOut\ShipOutMorePlusManualFulfillmentService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class ShipOutMorePlusManualFulfillmentController extends Controller
{
    public function __invoke(
        Request $request, Order $order, ShipOutMorePlusManualFulfillmentService $manual
    ): RedirectResponse {
        $data = $request->validate([
            'tracking_number' => ['required', 'string', 'max:128'],
            'outbound_reference' => ['required', 'string', 'max:90'],
            'confirmation' => ['required', 'string', 'max:64'],
        ]);
        abort_unless(
            hash_equals('CONFIRM_MOREPLUS_MANUAL_SHIPPED_'.$order->id, $data['confirmation']),
            422, '人工出库确认不一致'
        );
        try {
            $manual->confirm($order, $data['tracking_number'], $data['outbound_reference'], $request->user());
        } catch (RuntimeException $e) {
            abort(409, match ($e->getMessage()) {
                'SHIPOUT_R9_CONFIRMED_DUPLICATE_BLOCKED' => '此单已被仓库确认为重复订单，禁止再次操作发货',
                'SHIPOUT_R9_TRACKING_ALREADY_USED' => 'Tracking 已被其他订单使用，请核对',
                'SHIPOUT_R9_MANUAL_PROOF_REQUIRED' => '需要真实出库单号及有效 Tracking',
                default => '当前订单不允许直接确认人工发货，请先核对 Dede 审核、OMS 与出库证据',
            });
        }

        return redirect('/logistics/oversea/us-moreplus?view=shipped')
            ->with('success', '人工发货与真实 Tracking 已保存 ERP；网站同步交由同步模块执行。');
    }
}
