<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Logistics\Application\Xlwms\WarehouseBatchDispatch;
use App\Modules\Logistics\Application\Xlwms\WarehouseStatusRefresh;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class XlwmsWarehouseBatchController
{
    public function refresh(Request $request, WarehouseStatusRefresh $service): JsonResponse
    {
        return response()->json($service->run())->header('Cache-Control', 'no-store, private');
    }

    public function sync(Request $request, WarehouseBatchDispatch $service): JsonResponse
    {
        return response()->json($service->run((int) $request->user()->id))
            ->header('Cache-Control', 'no-store, private');
    }
}
