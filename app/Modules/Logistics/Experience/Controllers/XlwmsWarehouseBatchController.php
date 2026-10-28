<?php

namespace App\Modules\Logistics\Experience\Controllers;

use App\Modules\Logistics\Application\Xlwms\WarehouseBatchDispatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class XlwmsWarehouseBatchController
{
    public function sync(Request $request, WarehouseBatchDispatch $service): JsonResponse
    {
        return response()->json($service->run((int) $request->user()->id))
            ->header('Cache-Control', 'no-store, private');
    }
}
