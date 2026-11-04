<?php

namespace App\Modules\Kol\Experience\Controllers;

use App\Modules\Kol\Application\KolFulfillmentApprovalService;
use App\Modules\Orders\Infrastructure\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class KolFulfillmentApprovalController
{
    public function __invoke(Request $request, Order $order, KolFulfillmentApprovalService $service): RedirectResponse
    {
        $service->approve($order, $request->user());

        return back()->with('success', '网红发货订单已由主管审核通过，可进入待分仓。');
    }
}
