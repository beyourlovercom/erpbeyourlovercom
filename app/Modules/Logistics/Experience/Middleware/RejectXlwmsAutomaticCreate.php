<?php

namespace App\Modules\Logistics\Experience\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RejectXlwmsAutomaticCreate
{
    public function handle(Request $request, Closure $next): Response
    {
        // Owner's final US-SM V1: all API and browser-API CREATE paths are
        // permanently disabled. Only manual XLSX downloads are supported.
        return response()->json([
            'message' => '世迈仓现使用人工下单，请在 ERP 下载订单文件并到 OMS 手工导入。',
            'code' => 'XLWMS_MANUAL_ORDER_ONLY',
        ], 410)->header('Cache-Control', 'no-store, private');
    }
}
